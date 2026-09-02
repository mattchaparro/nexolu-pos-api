<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\OrderService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cupones de la tienda online.
 *
 * Lo que estas pruebas defienden: el precio lo pone el SERVIDOR. Del
 * comprador se acepta un codigo y nada mas -- ni el monto del descuento, ni
 * el total. Y un cupon con tope de usos tiene que agotarse de verdad, o deja
 * de ser un tope.
 */
class StorefrontCouponTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    private function tienda(): Business
    {
        $business = Business::factory()->create(['feature_flags' => ['online_store' => true]]);
        BusinessStoreSettings::factory()->create(['business_id' => $business->id, 'is_active' => true]);
        User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        return $business;
    }

    private function producto(Business $business, float $precio = 10000): Product
    {
        return Product::factory()->create([
            'business_id' => $business->id,
            'is_published' => true,
            'is_active' => true,
            'is_service' => false,
            'price' => $precio,
            'stock' => 100,
        ]);
    }

    private function cupon(Business $business, array $attrs = []): Discount
    {
        return Discount::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'name' => 'Lanzamiento',
            'code' => 'BIENVENIDA10',
            'type' => 'percentage',
            'value' => 10,
            'scope' => 'cart',
            'is_active' => true,
            ...$attrs,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function comprar(Business $business, Product $product, array $extra = []): TestResponse
    {
        return $this->postJson("/api/v1/storefront/{$business->slug}/orders", [
            'customer_name' => 'Compradora',
            'customer_phone' => '3001112233',
            'is_pickup' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ...$extra,
        ]);
    }

    public function test_un_cupon_valido_descuenta_del_total(): void
    {
        $business = $this->tienda();
        $product = $this->producto($business);
        $this->cupon($business);

        $response = $this->comprar($business, $product, ['coupon_code' => 'BIENVENIDA10']);

        $response->assertCreated()
            ->assertJsonPath('coupon_code', 'BIENVENIDA10')
            ->assertJsonPath('discount_amount', 2000)
            ->assertJsonPath('total', 18000);
    }

    /** El comprador no escribe el cupón como está en el volante. */
    public function test_el_codigo_no_distingue_mayusculas(): void
    {
        $business = $this->tienda();
        $product = $this->producto($business);
        $this->cupon($business);

        $this->comprar($business, $product, ['coupon_code' => '  bienvenida10 '])
            ->assertCreated()
            ->assertJsonPath('discount_amount', 2000);
    }

    /**
     * El caso que de verdad importa: del comprador solo se acepta el CODIGO.
     * Si mandara el monto o el total, se pondria el precio que quisiera.
     */
    public function test_el_comprador_no_puede_fijar_el_descuento(): void
    {
        $business = $this->tienda();
        $product = $this->producto($business);
        $this->cupon($business);

        $this->comprar($business, $product, [
            'coupon_code' => 'BIENVENIDA10',
            'discount_amount' => 19999,
            'total' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('discount_amount', 2000)
            ->assertJsonPath('total', 18000);
    }

    public function test_un_cupon_vencido_no_aplica_pero_no_tumba_la_compra(): void
    {
        $business = $this->tienda();
        $product = $this->producto($business);
        $this->cupon($business, ['ends_at' => now()->subDay()]);

        // Perder la venta porque el cupon vencio entre que lo escribio y
        // apreto comprar seria peor que cobrarle el precio de lista.
        $this->comprar($business, $product, ['coupon_code' => 'BIENVENIDA10'])
            ->assertCreated()
            ->assertJsonPath('discount_amount', 0)
            ->assertJsonPath('total', 20000);
    }

    public function test_un_cupon_agotado_deja_de_aplicar(): void
    {
        $business = $this->tienda();
        $product = $this->producto($business);
        $this->cupon($business, ['max_uses' => 1, 'used_count' => 1]);

        $this->comprar($business, $product, ['coupon_code' => 'BIENVENIDA10'])
            ->assertCreated()
            ->assertJsonPath('discount_amount', 0);
    }

    /** Sin esto, un cupón de un solo uso se canjea tantas veces como se quiera. */
    public function test_cada_pedido_consume_un_uso(): void
    {
        $business = $this->tienda();
        $product = $this->producto($business);
        $coupon = $this->cupon($business, ['max_uses' => 1]);

        $this->comprar($business, $product, ['coupon_code' => 'BIENVENIDA10'])
            ->assertCreated()
            ->assertJsonPath('discount_amount', 2000);

        $this->assertSame(1, $coupon->fresh()->used_count);

        // El segundo ya no lo alcanza.
        $this->comprar($business, $product, ['coupon_code' => 'BIENVENIDA10'])
            ->assertCreated()
            ->assertJsonPath('discount_amount', 0);
    }

    public function test_el_cupon_de_otro_negocio_no_sirve(): void
    {
        $business = $this->tienda();
        $otro = $this->tienda();
        $product = $this->producto($business);
        $this->cupon($otro);

        $this->comprar($business, $product, ['coupon_code' => 'BIENVENIDA10'])
            ->assertCreated()
            ->assertJsonPath('discount_amount', 0);
    }

    public function test_el_minimo_de_compra_se_respeta(): void
    {
        $business = $this->tienda();
        $product = $this->producto($business);
        $this->cupon($business, ['min_order_amount' => 50000]);

        $this->comprar($business, $product, ['coupon_code' => 'BIENVENIDA10'])
            ->assertCreated()
            ->assertJsonPath('discount_amount', 0);
    }

    public function test_validar_un_cupon_antes_de_comprar_dice_cuanto_descuenta(): void
    {
        $business = $this->tienda();
        $this->cupon($business);

        $this->postJson("/api/v1/storefront/{$business->slug}/coupons/validate", [
            'code' => 'bienvenida10',
            'subtotal' => 20000,
        ])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('amount', 2000);
    }

    /** El motivo se dice: "cupón inválido" a secas hace que lo intenten cinco veces. */
    public function test_validar_explica_por_que_no_aplica(): void
    {
        $business = $this->tienda();
        $this->cupon($business, ['min_order_amount' => 50000]);

        $this->postJson("/api/v1/storefront/{$business->slug}/coupons/validate", [
            'code' => 'BIENVENIDA10',
            'subtotal' => 20000,
        ])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('message', 'Este cupón aplica desde $50.000.');
    }

    /**
     * Un endpoint publico que distinguiera "no existe" de "no aplica aqui"
     * seria un adivinador de cupones ajenos.
     */
    public function test_validar_no_revela_cupones_de_otro_negocio(): void
    {
        $business = $this->tienda();
        $otro = $this->tienda();
        $this->cupon($otro);

        $this->postJson("/api/v1/storefront/{$business->slug}/coupons/validate", [
            'code' => 'BIENVENIDA10',
            'subtotal' => 20000,
        ])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('message', 'Ese cupón no existe.');
    }

    /**
     * La venta tiene que cobrar lo que se cobro.
     *
     * Encontrado en produccion con el pedido #4: el cupon se aplicaba al
     * pedido (15.800) pero la venta se creaba por el total sin descuento
     * (17.000). El comerciante quedaba con 1.200 de mas en sus ventas del
     * dia, en su cierre de caja y en el cuadre contra la pasarela -- que es
     * justo donde se vuelve un descuadre que nadie sabe explicar.
     */
    public function test_la_venta_del_pedido_confirmado_cobra_el_total_con_descuento(): void
    {
        $business = $this->tienda();
        $product = $this->producto($business);
        $this->cupon($business);

        $this->comprar($business, $product, ['coupon_code' => 'BIENVENIDA10'])
            ->assertCreated()
            ->assertJsonPath('total', 18000);

        $order = Order::withoutGlobalScopes()->where('business_id', $business->id)->latest('id')->first();
        $owner = User::withoutGlobalScopes()->where('business_id', $business->id)->first();

        app(OrderService::class)->transition(
            $owner, $order, Order::STATUS_CONFIRMED, 'Pago aprobado', 'transfer'
        );

        $sale = Sale::withoutGlobalScopes()->find($order->fresh()->sale_id);
        $this->assertNotNull($sale, 'Confirmar el pedido debe crear la venta.');
        $this->assertSame(
            18000.0,
            (float) $sale->total,
            'La venta no puede quedar por encima de lo que el comprador pago.'
        );
        $this->assertSame(2000.0, (float) $sale->cart_discount_amount);
    }

    /** Un descuento del mostrador (sin código) no es un cupón. */
    public function test_un_descuento_sin_codigo_no_se_puede_redimir(): void
    {
        $business = $this->tienda();
        $product = $this->producto($business);
        Discount::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'name' => 'Descuento del mostrador',
            'type' => 'percentage',
            'value' => 50,
            'scope' => 'cart',
            'is_active' => true,
        ]);

        $this->comprar($business, $product, ['coupon_code' => ''])
            ->assertCreated()
            ->assertJsonPath('discount_amount', 0);

        $this->assertSame(
            0,
            Order::withoutGlobalScopes()->where('business_id', $business->id)->whereNotNull('discount_id')->count()
        );
    }
}
