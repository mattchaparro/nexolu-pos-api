<?php

namespace Tests\Feature\Api\V1;

use App\Mail\NewOnlineOrderMail;
use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Support\BusinessFeaturePresets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Checkout publico y ciclo de vida del pedido.
 *
 * Lo que mas se prueba aca es que NADA de lo que manda el comprador se cree:
 * ni precios, ni envio, ni disponibilidad. Y que la venta solo nace al
 * confirmar.
 */
class StorefrontOrderTest extends TestCase
{
    use DatabaseTransactions;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
        ]);
        BusinessStoreSettings::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
            'shipping_flat_fee' => 5000,
        ]);
        $this->owner = User::factory()->create(['business_id' => $this->business->id, 'is_business_owner' => true]);
        $this->owner->assignRole('admin');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function publishedProduct(array $attributes = []): Product
    {
        $category = ProductCategory::factory()->create(['business_id' => $this->business->id, 'is_published' => true]);

        return Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'is_published' => true,
            'is_active' => true,
            'is_service' => false,
            'track_stock' => true,
            ...$attributes,
        ]);
    }

    private function checkout(array $payload = []): TestResponse
    {
        return $this->postJson("/api/v1/storefront/{$this->business->slug}/orders", [
            'customer_name' => 'Ana Comprador',
            'customer_phone' => '3001234567',
            'is_pickup' => true,
            ...$payload,
        ]);
    }

    // -----------------------------------------------------------------
    // El precio y el envio los pone el servidor
    // -----------------------------------------------------------------

    public function test_the_price_comes_from_the_database_not_from_the_buyer(): void
    {
        $product = $this->publishedProduct(['price' => 50000, 'stock' => 10]);

        $response = $this->checkout([
            // Un cliente malicioso mandando su propio precio.
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 1, 'subtotal' => 2]],
        ])->assertCreated();

        $this->assertEqualsWithDelta(100000, (float) $response->json('subtotal'), 0.01);
        $this->assertEqualsWithDelta(100000, (float) $response->json('total'), 0.01);
    }

    public function test_the_shipping_fee_comes_from_the_store_settings(): void
    {
        $product = $this->publishedProduct(['price' => 20000, 'stock' => 5]);

        $this->checkout([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'is_pickup' => false,
            'shipping_address' => 'Calle 45 #12-30',
            'shipping_city' => 'Bogotá',
            'shipping_fee' => 0,
        ])->assertCreated();

        $order = Order::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();
        $this->assertEqualsWithDelta(5000, (float) $order->shipping_fee, 0.01);
        $this->assertEqualsWithDelta(25000, (float) $order->total, 0.01);
    }

    public function test_picking_up_removes_the_shipping_fee(): void
    {
        $product = $this->publishedProduct(['price' => 20000, 'stock' => 5]);

        $response = $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();

        $this->assertEqualsWithDelta(0, (float) $response->json('shipping_fee'), 0.01);
        $this->assertEqualsWithDelta(20000, (float) $response->json('total'), 0.01);
    }

    public function test_a_delivery_without_address_is_rejected(): void
    {
        $product = $this->publishedProduct(['stock' => 5]);

        $this->checkout([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'is_pickup' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('shipping_address');
    }

    // -----------------------------------------------------------------
    // Disponibilidad
    // -----------------------------------------------------------------

    public function test_ordering_more_than_the_stock_is_rejected(): void
    {
        $product = $this->publishedProduct(['stock' => 2]);

        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 3]]])
            ->assertUnprocessable();
    }

    public function test_an_unpublished_product_cannot_be_ordered(): void
    {
        $product = $this->publishedProduct(['stock' => 5, 'is_published' => false]);

        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertUnprocessable();
    }

    public function test_a_product_of_another_business_cannot_be_ordered(): void
    {
        $foreign = Product::factory()->create(['is_published' => true, 'is_active' => true]);

        $this->checkout(['items' => [['product_id' => $foreign->id, 'quantity' => 1]]])
            ->assertUnprocessable();
    }

    public function test_a_pending_order_holds_stock_from_the_next_buyer(): void
    {
        // La reserva blanda: lo publicado es el stock menos lo comprometido.
        $product = $this->publishedProduct(['stock' => 3]);

        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 3]]])->assertCreated();

        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertUnprocessable();
    }

    public function test_an_expired_order_releases_its_reservation(): void
    {
        $product = $this->publishedProduct(['stock' => 3]);
        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 3]]])->assertCreated();

        Order::withoutGlobalScopes()->where('business_id', $this->business->id)
            ->update(['expires_at' => now()->subMinute()]);
        app(OrderService::class)->expireStale();

        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 3]]])->assertCreated();
    }

    // -----------------------------------------------------------------
    // Variantes
    // -----------------------------------------------------------------

    /** @return array{0: Product, 1: ProductVariant} */
    private function productWithVariant(int $stock = 5, float $price = 45000): array
    {
        $product = $this->publishedProduct(['stock' => 0, 'price' => 1]);
        $attribute = ProductAttribute::factory()->create(['business_id' => $this->business->id, 'name' => 'Talla']);
        $value = ProductAttributeValue::factory()->create([
            'product_attribute_id' => $attribute->id,
            'business_id' => $this->business->id,
            'value' => 'M',
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'business_id' => $this->business->id,
            'price' => $price,
            'stock' => $stock,
        ]);
        $variant->attributeValues()->sync([$value->id => ['product_attribute_id' => $attribute->id]]);

        return [$product, $variant];
    }

    public function test_a_product_with_variants_requires_choosing_one(): void
    {
        [$product] = $this->productWithVariant();

        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertUnprocessable();
    }

    public function test_the_variant_price_wins_and_its_label_is_copied(): void
    {
        [$product, $variant] = $this->productWithVariant(price: 45000);

        $response = $this->checkout([
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->assertEqualsWithDelta(90000, (float) $response->json('total'), 0.01);
        $this->assertSame('M', $response->json('items.0.variant_label'));
    }

    // -----------------------------------------------------------------
    // Ciclo de vida
    // -----------------------------------------------------------------

    public function test_confirming_creates_the_real_sale_and_moves_the_stock(): void
    {
        [$product, $variant] = $this->productWithVariant(stock: 5, price: 30000);
        $this->checkout([
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 2]],
        ])->assertCreated();

        $order = Order::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();
        $this->assertNull($order->sale_id, 'Un pedido pendiente todavia no es una venta');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => Order::STATUS_CONFIRMED])
            ->assertOk()
            ->assertJsonPath('status', Order::STATUS_CONFIRMED);

        $order->refresh();
        $this->assertNotNull($order->sale_id, 'Confirmar tiene que crear la venta');
        $this->assertSame(3, $variant->fresh()->stock, 'El stock sale al confirmar, no al pedir');

        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'type' => StockMovement::TYPE_SALE,
        ]);
    }

    /**
     * Regresion: `payment_method` estaba fijo en 'transfer' dentro de
     * OrderService. Cada negocio tiene su propio catalogo de medios
     * habilitados (business_pos_payment_methods / el JSON legacy), asi que
     * confirmar reventaba con "Metodo de pago no permitido" en cualquier
     * negocio que no lo tuviera - encontrado probando contra un negocio real
     * con cash/nequi/daviplata/credit. Los tests no lo veian porque la
     * factory deja el catalogo por defecto, que si incluye transferencia.
     */
    public function test_confirming_uses_the_payment_method_the_merchant_chose(): void
    {
        $this->business->update(['payment_methods' => [
            ['id' => 'cash', 'label' => 'Efectivo'],
            ['id' => 'nequi', 'label' => 'Nequi'],
        ]]);

        $product = $this->publishedProduct(['stock' => 5, 'price' => 10000]);
        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $order = Order::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", [
                'status' => Order::STATUS_CONFIRMED,
                'payment_method' => 'nequi',
            ])
            ->assertOk();

        $this->assertDatabaseHas('sales', [
            'id' => $order->fresh()->sale_id,
            'payment_method' => 'nequi',
        ]);
    }

    public function test_a_payment_method_the_business_does_not_have_is_rejected(): void
    {
        $this->business->update(['payment_methods' => [['id' => 'cash', 'label' => 'Efectivo']]]);

        $product = $this->publishedProduct(['stock' => 5, 'price' => 10000]);
        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $order = Order::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", [
                'status' => Order::STATUS_CONFIRMED,
                'payment_method' => 'transfer',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->assertNull($order->fresh()->sale_id, 'Un medio invalido no puede dejar la venta a medias');
    }

    /** Fiar un pedido online no tiene sentido: no hay mostrador donde fiarle a nadie. */
    public function test_an_online_order_cannot_be_confirmed_on_credit(): void
    {
        $this->business->update(['payment_methods' => [
            ['id' => 'cash', 'label' => 'Efectivo'],
            ['id' => 'credit', 'label' => 'Fiado'],
        ]]);

        $product = $this->publishedProduct(['stock' => 5, 'price' => 10000]);
        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $order = Order::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", [
                'status' => Order::STATUS_CONFIRMED,
                'payment_method' => 'credit',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');
    }

    /**
     * Regresion: SaleService ignoraba el envio recibido y usaba
     * `businesses.delivery_fee` (el domicilio del MOSTRADOR), y encima le
     * sumaba propina e ipoconsumo. Resultado: la venta cobraba un total
     * distinto del que el comprador acepto en la tienda. Encontrado con
     * datos reales - un pedido de $50.000 (45.000 + 5.000 de envio) creo una
     * venta de $50.000 compuesta de 45.000 + 500 de domicilio + 4.500 de
     * propina: el mismo numero por casualidad, con la plata en otro lado.
     */
    public function test_the_sale_charges_exactly_what_the_buyer_was_quoted(): void
    {
        $this->business->update([
            'delivery_enabled' => true,
            // El domicilio del mostrador, distinto del envio de la tienda.
            'delivery_fee' => 500,
            'service_charge_enabled' => true,
            'service_charge_rate' => 10,
        ]);

        $product = $this->publishedProduct(['stock' => 5, 'price' => 45000]);
        $this->checkout([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'is_pickup' => false,
            'shipping_address' => 'Calle 10 #5-20',
            'shipping_city' => 'Bogotá',
        ])->assertCreated();

        $order = Order::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();
        $this->assertEqualsWithDelta(50000, (float) $order->total, 0.01);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => Order::STATUS_CONFIRMED])
            ->assertOk();

        $sale = Sale::withoutGlobalScopes()->findOrFail($order->fresh()->sale_id);
        $this->assertEqualsWithDelta(50000, (float) $sale->total, 0.01, 'La venta cobra lo cotizado');
        $this->assertEqualsWithDelta(5000, (float) $sale->delivery_fee, 0.01, 'El envio es el de la tienda');
        $this->assertEqualsWithDelta(0, (float) $sale->service_charge_amount, 0.01, 'La tienda no cotiza propina');
    }

    // -----------------------------------------------------------------
    // Aviso al comerciante
    // -----------------------------------------------------------------

    public function test_a_new_order_emails_the_owner(): void
    {
        Mail::fake();
        $product = $this->publishedProduct(['stock' => 5, 'price' => 10000]);

        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();

        Mail::assertQueued(
            NewOnlineOrderMail::class,
            fn (NewOnlineOrderMail $mail) => $mail->hasTo($this->owner->email),
        );
    }

    public function test_the_store_can_send_the_alert_somewhere_else(): void
    {
        Mail::fake();
        BusinessStoreSettings::withoutGlobalScopes()
            ->where('business_id', $this->business->id)
            ->update(['order_email' => 'despachos@tienda.test']);

        $product = $this->publishedProduct(['stock' => 5, 'price' => 10000]);
        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();

        Mail::assertQueued(
            NewOnlineOrderMail::class,
            fn (NewOnlineOrderMail $mail) => $mail->hasTo('despachos@tienda.test'),
        );
    }

    public function test_the_alert_can_be_turned_off(): void
    {
        Mail::fake();
        BusinessStoreSettings::withoutGlobalScopes()
            ->where('business_id', $this->business->id)
            ->update(['order_email_enabled' => false]);

        $product = $this->publishedProduct(['stock' => 5, 'price' => 10000]);
        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();

        Mail::assertNothingQueued();
    }

    // -----------------------------------------------------------------
    // Avisos al comprador
    // -----------------------------------------------------------------

    /** Los avisos al comprador salen por el Communications Core. */
    private function fakeComms(): void
    {
        config([
            'services.comms_core.base_url' => 'http://comms.test',
            'services.comms_core.api_key' => 'comms-key',
        ]);
        Http::fake(['http://comms.test/*' => Http::response(['results' => []], 200)]);
    }

    public function test_the_buyer_is_told_the_order_arrived(): void
    {
        Mail::fake();
        $this->fakeComms();
        $product = $this->publishedProduct(['stock' => 5, 'price' => 10000]);

        $this->checkout([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'customer_email' => 'ana@compradora.test',
        ])->assertCreated();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/notifications/send')
            && $request['reference'] === 'pedido_recibido'
            && $request['to']['email'] === 'ana@compradora.test'
            && str_contains((string) $request['html'], 'Pedido recibido'));
    }

    /**
     * Lo que motivo unificar el envio: si WhatsApp y correo van en dos
     * llamadas, una puede salir y la otra no, y el comprador recibe la
     * mitad del aviso.
     */
    public function test_whatsapp_and_email_go_in_a_single_transaction(): void
    {
        Mail::fake();
        $this->fakeComms();
        config(['services.whatsapp.templates.pedido_recibido.name' => 'pedido_recibido_v1']);

        $product = $this->publishedProduct(['stock' => 5, 'price' => 10000]);
        $this->checkout([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'customer_email' => 'ana@compradora.test',
        ])->assertCreated();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/notifications/send')
            && $request['channels'] === ['whatsapp', 'email']
            && $request['to']['whatsapp'] !== null
            && $request['to']['email'] === 'ana@compradora.test'
            && $request['whatsapp_template']['name'] === 'pedido_recibido_v1');
    }

    /** Sin plantilla aprobada en Meta, el aviso sale solo por correo. */
    public function test_without_an_approved_template_only_email_goes_out(): void
    {
        Mail::fake();
        $this->fakeComms();
        $product = $this->publishedProduct(['stock' => 5, 'price' => 10000]);

        $this->checkout([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'customer_email' => 'ana@compradora.test',
        ])->assertCreated();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/notifications/send')
            && $request['channels'] === ['email']);
    }

    public function test_the_buyer_is_told_when_the_order_is_confirmed(): void
    {
        $product = $this->publishedProduct(['stock' => 5, 'price' => 10000]);
        $this->checkout([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'customer_email' => 'ana@compradora.test',
        ])->assertCreated();
        $order = Order::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();

        // Fake despues del checkout: interesa el aviso de la confirmacion.
        Mail::fake();
        $this->fakeComms();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => Order::STATUS_CONFIRMED])
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/notifications/send')
            && $request['reference'] === 'pedido_confirmado');
    }

    /**
     * Comprar como invitado significa que puede haber dejado solo el
     * telefono. Sin correo no se manda correo, y el pedido sigue igual.
     */
    public function test_an_order_without_an_email_still_works(): void
    {
        Mail::fake();
        $this->fakeComms();
        $product = $this->publishedProduct(['stock' => 5, 'price' => 10000]);

        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();

        // Sin correo ni plantilla no queda ningun canal: no se llama al Core.
        Http::assertNothingSent();
    }

    /** Estados internos: al comprador no le dicen nada y no se le escribe. */
    public function test_the_buyer_is_not_told_about_internal_statuses(): void
    {
        $product = $this->publishedProduct(['stock' => 5, 'price' => 10000]);
        $this->checkout([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'customer_email' => 'ana@compradora.test',
        ])->assertCreated();
        $order = Order::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => Order::STATUS_CONFIRMED])
            ->assertOk();

        Mail::fake();
        $this->fakeComms();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => Order::STATUS_PREPARING])
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_an_invalid_transition_is_rejected(): void
    {
        $product = $this->publishedProduct(['stock' => 5]);
        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $order = Order::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();

        // Pendiente no puede saltar directo a entregado sin pasar por caja.
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => Order::STATUS_DELIVERED])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_the_buyer_can_follow_the_order_with_its_token(): void
    {
        $product = $this->publishedProduct(['stock' => 5, 'price' => 10000]);
        $token = $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertCreated()
            ->json('token');

        $this->getJson("/api/v1/storefront/{$this->business->slug}/orders/{$token}")
            ->assertOk()
            ->assertJsonPath('customer_name', 'Ana Comprador');
    }

    public function test_a_token_of_another_store_does_not_work(): void
    {
        $product = $this->publishedProduct(['stock' => 5]);
        $token = $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->json('token');

        $other = Business::factory()->create([
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
        ]);
        BusinessStoreSettings::factory()->create(['business_id' => $other->id, 'is_active' => true]);

        $this->getJson("/api/v1/storefront/{$other->slug}/orders/{$token}")->assertNotFound();
    }

    public function test_orders_of_another_business_are_invisible_in_the_inbox(): void
    {
        $product = $this->publishedProduct(['stock' => 5]);
        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();

        $otherOwner = User::factory()->create(['business_id' => Business::factory()->create([
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
        ])->id]);
        $otherOwner->assignRole('admin');

        $this->actingAs($otherOwner, 'sanctum')
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_buyer_becomes_a_client_of_the_business(): void
    {
        $product = $this->publishedProduct(['stock' => 5]);
        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();

        $this->assertDatabaseHas('clients', [
            'business_id' => $this->business->id,
            'phone' => '3001234567',
            'name' => 'Ana Comprador',
        ]);
    }

    public function test_a_minimum_order_amount_is_enforced(): void
    {
        BusinessStoreSettings::withoutGlobalScopes()
            ->where('business_id', $this->business->id)
            ->update(['min_order_amount' => 50000]);
        $product = $this->publishedProduct(['stock' => 5, 'price' => 10000]);

        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
    }

    public function test_ordering_from_a_closed_store_returns_404(): void
    {
        BusinessStoreSettings::withoutGlobalScopes()
            ->where('business_id', $this->business->id)->update(['is_active' => false]);
        $product = $this->publishedProduct(['stock' => 5]);

        $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertNotFound();
    }

    public function test_order_numbers_are_sequential_per_business(): void
    {
        $product = $this->publishedProduct(['stock' => 50, 'price' => 1000]);

        $first = $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->json('number');
        $second = $this->checkout(['items' => [['product_id' => $product->id, 'quantity' => 1]]])->json('number');

        $this->assertSame(1, $first);
        $this->assertSame(2, $second);
    }
}
