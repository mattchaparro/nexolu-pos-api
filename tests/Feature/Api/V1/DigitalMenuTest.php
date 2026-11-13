<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Menú digital: el módulo del restaurante. Usa el mismo motor de la tienda
 * online (productos publicados, carrito, pedidos) pero es otro módulo, con
 * otro nombre y sin depender de que la tienda esté encendida.
 */
class DigitalMenuTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    /** @return array{0: Business, 1: User} */
    private function restaurant(array $flags = []): array
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [
                ...BusinessFeaturePresets::full(),
                'online_store' => false,
                'digital_menu' => true,
                ...$flags,
            ],
        ]);
        BusinessStoreSettings::factory()->create(['business_id' => $business->id, 'is_active' => true]);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $owner->assignRole('admin');

        return [$business, $owner];
    }

    private function dish(Business $business): Product
    {
        $category = ProductCategory::factory()->create(['business_id' => $business->id, 'is_published' => true]);

        return Product::factory()->create([
            'business_id' => $business->id,
            'category_id' => $category->id,
            'is_published' => true,
            'is_active' => true,
            'is_service' => false,
            'track_stock' => false,
            'price' => 22000,
        ]);
    }

    public function test_a_restaurant_with_only_the_menu_has_a_public_menu(): void
    {
        [$business] = $this->restaurant();
        $this->dish($business);

        $this->getJson("/api/v1/storefront/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('kind', 'menu');
        $this->getJson("/api/v1/storefront/{$business->slug}/products")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_without_menu_nor_store_there_is_nothing_public(): void
    {
        [$business] = $this->restaurant(['digital_menu' => false]);

        $this->getJson("/api/v1/storefront/{$business->slug}")->assertNotFound();
    }

    public function test_the_restaurant_manages_its_menu_and_orders_without_the_online_store(): void
    {
        [$business, $owner] = $this->restaurant();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/store-settings')
            ->assertOk()
            ->assertJsonPath('kind', 'menu')
            ->assertJsonPath('menu_url', rtrim((string) config('app.storefront_url'), '/')."/{$business->slug}/menu");

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/orders')->assertOk();
    }

    public function test_store_only_tools_stay_with_the_online_store(): void
    {
        [$business, $owner] = $this->restaurant();
        $dish = $this->dish($business);

        // Ventas cruzadas ("completa tu pedido") son de la tienda, no del menú.
        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/products/{$dish->id}/cross-sells")
            ->assertForbidden();
    }

    public function test_a_menu_order_reaches_the_kitchen_and_the_buyer_gets_no_whatsapp_from_nexolu(): void
    {
        [$business, $owner] = $this->restaurant(['kitchen_board' => true]);
        $dish = $this->dish($business);

        $this->postJson("/api/v1/storefront/{$business->slug}/orders", [
            'customer_name' => 'Ana',
            'customer_phone' => '3001234567',
            'is_pickup' => true,
            'items' => [['product_id' => $dish->id, 'quantity' => 2]],
        ])->assertCreated();

        $order = Order::withoutGlobalScopes()->where('business_id', $business->id)->firstOrFail();
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => Order::STATUS_CONFIRMED])
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/kitchen/tickets')
            ->assertOk()
            ->assertJsonPath('0.customer_name', 'Ana');

        // El restaurante le escribe desde su WhatsApp; Nexolú no le manda nada.
        $this->assertDatabaseMissing('whatsapp_logs', [
            'business_id' => $business->id,
            'to_phone' => '573001234567',
        ]);
    }

    public function test_the_link_page_points_to_the_menu(): void
    {
        [$business] = $this->restaurant();

        $this->getJson("/api/v1/link-page/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('kind', 'menu')
            ->assertJsonPath('has_store', true);
    }

    public function test_the_restaurant_setup_brings_the_menu_and_not_the_store(): void
    {
        $preset = BusinessFeaturePresets::foodService();

        $this->assertTrue($preset['digital_menu']);
        $this->assertFalse($preset['online_store']);
        $this->assertContains('digital_menu', array_column(BusinessFeaturePresets::catalog(), 'key'));
    }

    public function test_an_old_business_without_flags_does_not_get_a_public_menu_by_itself(): void
    {
        $business = Business::factory()->create(['feature_flags' => null]);

        $this->assertFalse($business->hasFeature('digital_menu'));
    }
}
