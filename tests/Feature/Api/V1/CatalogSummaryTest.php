<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CatalogSummaryTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(array $businessAttributes = []): User
    {
        $business = Business::factory()->create($businessAttributes);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_products_summary_counts_low_stock_out_of_stock_and_single_sale(): void
    {
        $admin = $this->admin(['feature_flags' => ['ingredients' => false]]);

        Product::factory()->create(['business_id' => $admin->business_id, 'stock' => 0, 'is_service' => false]);
        Product::factory()->create([
            'business_id' => $admin->business_id, 'stock' => 3, 'low_stock_alert_threshold' => 5, 'is_service' => false,
        ]);
        Product::factory()->create([
            'business_id' => $admin->business_id, 'stock' => 100, 'low_stock_alert_threshold' => 5, 'is_service' => false,
        ]);
        Product::factory()->create([
            'business_id' => $admin->business_id, 'is_single_sale' => true, 'stock' => 1, 'is_service' => false,
        ]);
        // Un servicio no cuenta para ninguno de los totales de inventario.
        Product::factory()->create(['business_id' => $admin->business_id, 'is_service' => true, 'stock' => 0]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/products/summary');

        $response->assertOk()->assertJson([
            'low_stock_count' => 3,
            'out_of_stock_count' => 1,
            'single_sale_count' => 1,
            'with_recipe_count' => 0,
            'show_inventory_value_card' => true,
        ]);
        $this->assertIsNumeric($response->json('inventory_value_cop'));
    }

    public function test_products_summary_hides_inventory_value_card_when_ingredients_feature_enabled(): void
    {
        $admin = $this->admin(['feature_flags' => ['ingredients' => true]]);
        Product::factory()->create(['business_id' => $admin->business_id, 'is_service' => false]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/products/summary');

        $response->assertOk()->assertJson([
            'show_inventory_value_card' => false,
            'inventory_value_cop' => null,
        ]);
    }

    public function test_ingredients_summary_counts_active_and_low_stock(): void
    {
        $admin = $this->admin(['feature_flags' => ['ingredients' => true]]);

        Ingredient::factory()->create(['business_id' => $admin->business_id, 'stock' => 1, 'min_stock' => 5, 'is_active' => true]);
        Ingredient::factory()->create(['business_id' => $admin->business_id, 'stock' => 50, 'min_stock' => 5, 'is_active' => true]);
        // Inactivo pero con stock alto: no debe sumar a low_stock_count (que
        // no filtra por is_active, igual que el legacy) para que el assert
        // de abajo no dependa de los defaults de la factory.
        Ingredient::factory()->create(['business_id' => $admin->business_id, 'stock' => 50, 'min_stock' => 5, 'is_active' => false]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/ingredients/summary');

        $response->assertOk()->assertJson(['active_count' => 2, 'low_stock_count' => 1]);
    }
}
