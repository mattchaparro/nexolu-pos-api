<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\User;
use App\Services\OnlineOrderPaymentService;
use App\Support\BusinessFeaturePresets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Restaurantes: el pedido online confirmado (pagado en línea o verificado por
 * el negocio) es una venta cerrada que entra a la comandera con su nota, y
 * sale de ella al despacharse. La tienda puede ser solo un menú sin pedidos.
 */
class OnlineOrderKitchenTest extends TestCase
{
    use DatabaseTransactions;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = $this->business(kitchen: true);
        $this->owner = User::factory()->create(['business_id' => $this->business->id, 'is_business_owner' => true]);
        $this->owner->assignRole('admin');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function business(bool $kitchen, array $settings = []): Business
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true, 'kitchen_board' => $kitchen, 'charges' => true],
            'delivery_enabled' => true,
            'delivery_fee' => 4000,
            'service_charge_enabled' => false,
            'ipoconsumo_enabled' => false,
        ]);
        BusinessStoreSettings::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
            ...$settings,
        ]);

        return $business;
    }

    private function product(Business $business, float $price = 18000): Product
    {
        $category = ProductCategory::factory()->create(['business_id' => $business->id, 'is_published' => true]);

        return Product::factory()->create([
            'business_id' => $business->id,
            'category_id' => $category->id,
            'is_published' => true,
            'is_active' => true,
            'is_service' => false,
            'track_stock' => false,
            'price' => $price,
        ]);
    }

    private function checkout(Business $business, array $payload = []): TestResponse
    {
        return $this->postJson("/api/v1/storefront/{$business->slug}/orders", [
            'customer_name' => 'Ana Comprador',
            'customer_phone' => '3001234567',
            'is_pickup' => false,
            'shipping_address' => 'Calle 10 # 5-20',
            'shipping_city' => 'Sibaté',
            'shipping_notes' => 'Sin cebolla, por favor',
            ...$payload,
        ]);
    }

    private function confirm(Business $business, ?User $actor = null): Order
    {
        $order = Order::withoutGlobalScopes()->where('business_id', $business->id)->firstOrFail();

        $this->actingAs($actor ?? $this->owner, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => Order::STATUS_CONFIRMED])
            ->assertOk();

        return $order->fresh();
    }

    public function test_confirming_an_online_order_makes_a_closed_sale_that_goes_to_the_kitchen(): void
    {
        $product = $this->product($this->business);
        $this->checkout($this->business, ['items' => [['product_id' => $product->id, 'quantity' => 2]]])->assertCreated();

        $order = $this->confirm($this->business);

        $sale = Sale::withoutGlobalScopes()->findOrFail($order->sale_id);
        $this->assertNotSame('open', $sale->status, 'Un pedido online es un pedido, no una cuenta abierta');
        $this->assertTrue((bool) $sale->is_delivery);
        $this->assertEquals(4000, (float) $sale->delivery_fee, 'El envío es el domicilio del negocio');
        // La nota es la del comprador; la direccion va aparte.
        $this->assertSame('Sin cebolla, por favor', $sale->notes);
        $this->assertSame('Calle 10 # 5-20, Sibaté', $sale->delivery_address);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/kitchen/tickets')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $sale->id)
            ->assertJsonPath('0.customer_name', 'Ana Comprador')
            ->assertJsonPath('0.customer_phone', '3001234567')
            ->assertJsonPath('0.is_delivery', true)
            ->assertJsonPath('0.delivery_address', 'Calle 10 # 5-20, Sibaté')
            ->assertJsonPath('0.notes', 'Sin cebolla, por favor')
            ->assertJsonPath('0.kitchen_status', 'pending')
            ->assertJsonPath('0.online_order.number', $order->number)
            ->assertJsonPath('0.online_order.is_paid_online', false)
            ->assertJsonPath('0.items.0.quantity', 2);
    }

    public function test_an_order_paid_online_goes_to_the_kitchen_too(): void
    {
        $product = $this->product($this->business);
        $this->checkout($this->business, ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $order = Order::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();
        $order->forceFill(['payment_provider' => 'bold'])->save();

        $this->assertTrue(app(OnlineOrderPaymentService::class)->approve($order));

        $order->refresh();
        $this->assertNotNull($order->paid_at);
        $this->assertNotSame('open', Sale::withoutGlobalScopes()->findOrFail($order->sale_id)->status);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/kitchen/tickets')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.online_order.number', $order->number)
            ->assertJsonPath('0.online_order.is_paid_online', true);
    }

    public function test_the_kitchen_ticket_carries_the_notes_of_the_order(): void
    {
        $product = $this->product($this->business);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/open-tabs', [
                'customer_name' => 'Mesa de Juan',
                'notes' => 'Alergia a la nuez',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/kitchen/tickets')
            ->assertOk()
            ->assertJsonPath('0.notes', 'Alergia a la nuez')
            ->assertJsonPath('0.online_order', null);
    }

    public function test_without_a_kitchen_the_order_is_a_closed_sale_outside_any_board(): void
    {
        $business = $this->business(kitchen: false);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $owner->assignRole('admin');
        $product = $this->product($business);

        $this->checkout($business, ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $order = $this->confirm($business, $owner);

        $sale = Sale::withoutGlobalScopes()->findOrFail($order->sale_id);
        $this->assertNotSame('open', $sale->status);
        $this->assertNull($sale->kitchen_status);
    }

    public function test_a_credit_payment_method_is_rejected_when_confirming(): void
    {
        $product = $this->product($this->business);
        $this->checkout($this->business, ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $order = Order::withoutGlobalScopes()->where('business_id', $this->business->id)->firstOrFail();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", [
                'status' => Order::STATUS_CONFIRMED,
                'payment_method' => 'credit',
            ])
            ->assertUnprocessable();

        $this->assertNull($order->fresh()->sale_id);
    }

    public function test_shipping_tip_and_ipoconsumo_come_from_the_business_settings(): void
    {
        // Ensayo del 13-nov: el menú cobraba su propio envío ($5.000) y el
        // negocio tenía otro ($500). Ahora hay uno solo, el del negocio.
        $this->business->update([
            'delivery_enabled' => true, 'delivery_fee' => 3000,
            'service_charge_enabled' => true, 'service_charge_rate' => 10,
            'ipoconsumo_enabled' => true, 'ipoconsumo_rate' => 8,
        ]);
        $product = $this->product($this->business, 20000);

        $this->getJson("/api/v1/storefront/{$this->business->slug}")
            ->assertOk()
            ->assertJsonPath('shipping_flat_fee', 3000)
            ->assertJsonPath('service_charge_rate', 10)
            ->assertJsonPath('ipoconsumo_rate', 8);

        $this->checkout($this->business, ['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertCreated()
            ->assertJsonPath('shipping_fee', 3000)
            ->assertJsonPath('service_charge', 2000)
            ->assertJsonPath('ipoconsumo', 1600)
            ->assertJsonPath('total', 26600);

        // Si el negocio cambia sus tarifas antes de confirmar, se cobra lo
        // que el comprador vio.
        $this->business->update(['delivery_fee' => 9000, 'service_charge_rate' => 15]);
        $sale = Sale::findOrFail($this->confirm($this->business)->sale_id);

        $this->assertSame('26600.00', $sale->total);
        $this->assertEquals(3000, (float) $sale->delivery_fee);
        $this->assertEquals(2000, (float) $sale->service_charge_amount);
        $this->assertEquals(1600, (float) $sale->ipoconsumo_amount);
    }

    public function test_the_buyer_can_leave_out_the_suggested_tip(): void
    {
        $this->business->update(['service_charge_enabled' => true, 'service_charge_rate' => 10]);
        $product = $this->product($this->business, 20000);

        $this->checkout($this->business, [
            'include_service_charge' => false,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])
            ->assertCreated()
            ->assertJsonPath('service_charge', 0)
            ->assertJsonPath('total', 24000);
    }

    public function test_the_kitchen_moves_the_order_and_handing_it_over_takes_it_off_the_board(): void
    {
        $product = $this->product($this->business);
        $this->checkout($this->business, [
            'is_pickup' => true,
            'shipping_address' => null,
            'shipping_city' => null,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();
        $order = $this->confirm($this->business);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/kitchen/tickets/{$order->sale_id}/status", ['kitchen_status' => 'preparing'])
            ->assertOk();
        $this->assertSame(Order::STATUS_PREPARING, $order->fresh()->status, 'El comprador ve que ya lo están preparando');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/kitchen/tickets/{$order->sale_id}/status", ['kitchen_status' => 'ready'])
            ->assertOk();
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/kitchen/tickets/{$order->sale_id}/dispatch")
            ->assertOk();

        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/kitchen/tickets')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_a_pickup_order_is_delivered_when_dispatched(): void
    {
        $product = $this->product($this->business);
        $this->checkout($this->business, [
            'is_pickup' => true,
            'shipping_address' => null,
            'shipping_city' => null,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();
        $order = $this->confirm($this->business);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/kitchen/tickets/{$order->sale_id}/dispatch")
            ->assertOk();

        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);
    }

    public function test_an_open_tab_cannot_be_dispatched(): void
    {
        $product = $this->product($this->business);
        $tab = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/open-tabs', ['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertCreated()
            ->json('id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/kitchen/tickets/{$tab}/dispatch")
            ->assertUnprocessable();
    }

    public function test_a_pickup_order_is_not_a_delivery(): void
    {
        $product = $this->product($this->business);
        $this->checkout($this->business, [
            'is_pickup' => true,
            'shipping_address' => null,
            'shipping_city' => null,
            'shipping_notes' => null,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $sale = Sale::withoutGlobalScopes()->findOrFail($this->confirm($this->business)->sale_id);

        $this->assertFalse((bool) $sale->is_delivery);
        $this->assertSame(0.0, (float) $sale->delivery_fee);
        $this->assertNull($sale->delivery_address);
        $this->assertNull($sale->delivery_status, 'Lo que se recoge no es un domicilio');
    }

    public function test_a_menu_only_store_refuses_orders(): void
    {
        $business = $this->business(kitchen: true, settings: ['menu_mode' => true]);
        $product = $this->product($business);

        $this->checkout($business, ['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_the_menu_mode_is_published_and_the_owner_can_toggle_it(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/v1/store-settings', ['menu_mode' => true])
            ->assertOk()
            ->assertJsonPath('menu_mode', true);

        $this->getJson("/api/v1/storefront/{$this->business->slug}")
            ->assertOk()
            ->assertJsonPath('menu_mode', true);

        $this->getJson("/api/v1/link-page/{$this->business->slug}")
            ->assertOk()
            ->assertJsonPath('menu_mode', true);
    }
}
