<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessPaymentGateway;
use App\Models\BusinessStoreSettings;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OnlineOrderPaymentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Confirmar el pago preguntandole a la pasarela, sin esperar el webhook.
 *
 * Por que existe: Bold NO manda webhooks en su ambiente de pruebas (solo con
 * el boton "Probar el webhook" de su panel), y en produccion se toma hasta
 * 10 minutos. El comprador vuelve a la tienda en segundos. Sin esta consulta
 * activa ve su pedido como "esperando el pago" -- con un boton que lo invita
 * a pagar otra vez algo que acaba de pagar. Paso de verdad, con el pedido #3
 * en produccion.
 */
class StorefrontOrderPaymentSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    private function businessConTienda(): Business
    {
        $business = Business::factory()->create(['feature_flags' => ['online_store' => true]]);
        BusinessStoreSettings::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
        ]);
        // `sales.user_id` es NOT NULL: la venta online la firma el dueño.
        User::factory()->create([
            'business_id' => $business->id,
            'is_business_owner' => true,
        ]);

        return $business;
    }

    private function gateway(Business $business): BusinessPaymentGateway
    {
        return BusinessPaymentGateway::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'provider_slug' => BusinessPaymentGateway::PROVIDER_BOLD,
            'environment' => 'production',
            'payments_core_merchant_id' => 'merch_1',
            'integration_api_key' => 'nxl_test',
            'webhook_secret' => 'whsec_test',
            'is_active' => true,
        ]);
    }

    private function pedidoPendiente(Business $business): Order
    {
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'is_published' => true,
            'is_active' => true,
            'is_service' => false,
        ]);

        $order = Order::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'number' => 1,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 12000,
            'shipping_fee' => 0,
            'total' => 12000,
            'customer_name' => 'Compradora',
            'customer_phone' => '3000000000',
            'is_pickup' => true,
            'public_token' => 'tok_'.uniqid(),
            'payment_reference' => 'pay_abc123',
            'payment_provider' => 'bold',
            'expires_at' => now()->addMinutes(20),
        ]);

        OrderItem::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 12000,
            'subtotal' => 12000,
        ]);

        return $order->fresh();
    }

    public function test_confirma_el_pedido_cuando_la_pasarela_dice_que_se_pago(): void
    {
        $business = $this->businessConTienda();
        $this->gateway($business);
        $order = $this->pedidoPendiente($business);

        Http::fake([
            '*/payments/transactions/*/refresh' => Http::response([
                'reference' => 'pay_abc123',
                'status' => 'approved',
                'provider_transaction_id' => 'BOLD_TX_1',
            ]),
        ]);

        $response = $this->postJson(
            "/api/v1/storefront/{$business->slug}/orders/{$order->public_token}/sync-payment"
        );

        $response->assertOk();
        $this->assertSame(Order::STATUS_CONFIRMED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);
    }

    public function test_no_toca_el_pedido_si_la_pasarela_sigue_sin_resolver(): void
    {
        $business = $this->businessConTienda();
        $this->gateway($business);
        $order = $this->pedidoPendiente($business);

        Http::fake([
            '*/payments/transactions/*/refresh' => Http::response([
                'reference' => 'pay_abc123',
                'status' => 'pending',
            ]),
        ]);

        $this->postJson(
            "/api/v1/storefront/{$business->slug}/orders/{$order->public_token}/sync-payment"
        )->assertOk();

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertNull($order->fresh()->paid_at);
    }

    /**
     * Que la pasarela este caida no puede dejar al comprador sin su pagina:
     * ve el pedido tal cual esta, que es lo que veria sin esta funcion.
     */
    public function test_si_la_pasarela_falla_devuelve_el_pedido_igual(): void
    {
        $business = $this->businessConTienda();
        $this->gateway($business);
        $order = $this->pedidoPendiente($business);

        Http::fake([
            '*/payments/transactions/*/refresh' => Http::response(['detail' => 'boom'], 502),
        ]);

        $this->postJson(
            "/api/v1/storefront/{$business->slug}/orders/{$order->public_token}/sync-payment"
        )->assertOk()->assertJsonPath('status', Order::STATUS_PENDING);
    }

    /** El token de un negocio no abre el pedido de otro. */
    public function test_el_token_de_otro_negocio_no_encuentra_el_pedido(): void
    {
        $business = $this->businessConTienda();
        $otro = $this->businessConTienda();
        $this->gateway($business);
        $order = $this->pedidoPendiente($business);

        Http::fake();

        $this->postJson(
            "/api/v1/storefront/{$otro->slug}/orders/{$order->public_token}/sync-payment"
        )->assertNotFound();
    }

    /**
     * Idempotencia contra el webhook: si llego primero, consultar no debe
     * volver a facturar. Un pedido ya confirmado ni siquiera llama a la
     * pasarela -- seria gastar una peticion para no hacer nada.
     */
    public function test_un_pedido_ya_confirmado_no_vuelve_a_consultar_ni_a_facturar(): void
    {
        $business = $this->businessConTienda();
        $this->gateway($business);
        $order = $this->pedidoPendiente($business);
        $order->forceFill(['status' => Order::STATUS_CONFIRMED, 'paid_at' => now()])->save();

        Http::fake();

        $this->postJson(
            "/api/v1/storefront/{$business->slug}/orders/{$order->public_token}/sync-payment"
        )->assertOk();

        Http::assertNothingSent();
    }

    /**
     * El caso que de verdad rompio produccion (pedido #3): dos caminos
     * aprobando el MISMO pedido.
     *
     * Al confirmar activamente, el Core resuelve la transaccion y al
     * resolverla dispara su propio webhook. Los dos llegan a `approve()` con
     * un segundo de diferencia -- y el objeto `Order` que trae la consulta se
     * cargo antes, con `status = pending`. Con la guarda leyendo ese estado
     * en memoria, los dos pasaban: dos ventas y el stock descontado doble.
     */
    public function test_dos_aprobaciones_del_mismo_pedido_crean_una_sola_venta(): void
    {
        $business = $this->businessConTienda();
        $order = $this->pedidoPendiente($business);

        // DOS instancias cargadas por separado, como en produccion: una la
        // trae el controlador de webhooks y la otra el de la tienda, y las
        // dos leyeron `pending` de la base antes de que ninguna facturara.
        // Reusar el mismo objeto no reproduciria nada: la primera aprobacion
        // le deja el estado nuevo en memoria y la segunda lo veria.
        $delWebhook = Order::withoutGlobalScopes()->find($order->id);
        $deLaTienda = Order::withoutGlobalScopes()->find($order->id);

        $servicio = app(OnlineOrderPaymentService::class);
        $this->assertTrue($servicio->approve($delWebhook));
        $this->assertFalse($servicio->approve($deLaTienda));

        $ventas = Sale::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->count();
        $this->assertSame(1, $ventas, 'Un pedido pagado una vez debe producir una sola venta.');

        $movimientos = StockMovement::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('type', 'sale')
            ->count();
        $this->assertSame(1, $movimientos, 'El stock no puede descontarse dos veces.');
    }
}
