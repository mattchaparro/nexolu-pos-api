<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\BusinessFeaturePresets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Los limites de la tienda/menú van por contador separado. Antes todos
 * compartian la llave dominio + IP: mirar la carta gastaba el cupo del
 * checkout (20 por minuto) y el comprador no podia hacer el pedido.
 */
class StorefrontThrottleTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    /** @return array{0: Business, 1: Product} */
    private function menu(): array
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [
                ...BusinessFeaturePresets::full(),
                'online_store' => false,
                'digital_menu' => true,
                'kitchen_board' => false,
            ],
        ]);
        BusinessStoreSettings::factory()->create(['business_id' => $business->id, 'is_active' => true]);
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $dish = Product::factory()->create([
            'business_id' => $business->id,
            'category_id' => $category->id,
            'is_published' => true,
            'is_active' => true,
            'is_service' => false,
            'track_stock' => false,
            'price' => 15000,
        ]);

        return [$business, $dish];
    }

    private function order(Business $business, Product $dish)
    {
        return $this->postJson("/api/v1/storefront/{$business->slug}/orders", [
            'customer_name' => 'Ana',
            'customer_phone' => '3001234567',
            'is_pickup' => true,
            'items' => [['product_id' => $dish->id, 'quantity' => 1]],
        ]);
    }

    public function test_browsing_the_menu_does_not_use_up_the_checkout_limit(): void
    {
        [$business, $dish] = $this->menu();

        for ($i = 0; $i < 25; $i++) {
            $this->getJson("/api/v1/storefront/{$business->slug}")->assertOk();
        }

        $this->order($business, $dish)->assertCreated();
    }

    public function test_too_many_orders_get_a_spanish_message(): void
    {
        [$business, $dish] = $this->menu();

        for ($i = 0; $i < 20; $i++) {
            $this->order($business, $dish)->assertCreated();
        }

        $this->order($business, $dish)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Demasiados intentos. Espera un momento y vuelve a intentar.')
            ->assertHeader('Retry-After');
    }
}
