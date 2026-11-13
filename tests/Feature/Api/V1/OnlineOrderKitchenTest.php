<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Restaurantes: el pedido online llega a la comandera como cuenta abierta,
 * con su nota, y la tienda puede ser solo un menú sin pedidos.
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
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true, 'kitchen_board' => $kitchen],
        ]);
        BusinessStoreSettings::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
            'shipping_flat_fee' => 4000,
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

    public function test_confirming_an_online_order_opens_a_tab_that_shows_in_the_kitchen(): void
    {
        $product = $this->product($this->business);
        $this->checkout($this->business, ['items' => [['product_id' => $product->id, 'quantity' => 2]]])->assertCreated();

        $order = $this->confirm($this->business);

        $sale = Sale::withoutGlobalScopes()->findOrFail($order->sale_id);
        $this->assertSame('open', $sale->status, 'Con comandera el pedido es una cuenta abierta, no una venta cerrada');
        $this->assertTrue((bool) $sale->is_delivery);
        $this->assertEquals(4000, (float) $sale->delivery_fee, 'El envío es el que se le cotizó al comprador');
        $this->assertStringContainsString("Pedido online #{$order->number}", (string) $sale->notes);
        $this->assertStringContainsString('Calle 10 # 5-20', (string) $sale->notes);
        $this->assertStringContainsString('Sin cebolla', (string) $sale->notes);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/kitchen/tickets')
            ->assertOk()
            ->assertJsonPath('0.id', $sale->id)
            ->assertJsonPath('0.customer_name', 'Ana Comprador')
            ->assertJsonPath('0.customer_phone', '3001234567')
            ->assertJsonPath('0.is_delivery', true)
            ->assertJsonPath('0.kitchen_status', 'pending')
            ->assertJsonPath('0.items.0.quantity', 2);
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
            ->assertJsonPath('0.notes', 'Alergia a la nuez');
    }

    public function test_without_a_kitchen_the_order_is_a_closed_sale_as_before(): void
    {
        $business = $this->business(kitchen: false);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $owner->assignRole('admin');
        $product = $this->product($business);

        $this->checkout($business, ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        $order = $this->confirm($business, $owner);

        $this->assertNotSame('open', Sale::withoutGlobalScopes()->findOrFail($order->sale_id)->status);
    }

    public function test_an_order_already_paid_online_stays_a_closed_sale(): void
    {
        $product = $this->product($this->business);
        $this->checkout($this->business, ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();
        Order::withoutGlobalScopes()->where('business_id', $this->business->id)->update(['paid_at' => now()]);

        $order = $this->confirm($this->business);

        $this->assertNotSame('open', Sale::withoutGlobalScopes()->findOrFail($order->sale_id)->status);
    }

    public function test_a_credit_payment_method_is_still_rejected_when_confirming_for_the_kitchen(): void
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

    public function test_a_pickup_order_is_not_a_delivery_and_says_so_in_the_note(): void
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
        $this->assertStringContainsString('Recoge en el local', (string) $sale->notes);
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
    }
}
