<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BulkStockUpdateTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_bulk_update_applies_name_stock_cost_and_price_changes(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create([
            'business_id' => $admin->business_id,
            'name' => 'Gaseosa',
            'stock' => 10,
            'cost_price' => 1000,
            'price' => 2000,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/products/bulk-update', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'new_name' => 'Gaseosa Colombiana',
                    'new_stock' => 15,
                    'new_cost' => 1200,
                    'new_price' => 2500,
                ],
            ],
            'notes' => 'Conteo fisico',
        ]);

        $response->assertOk()->assertJson([
            'name_count' => 1,
            'stock_count' => 1,
            'cost_count' => 1,
            'price_count' => 1,
        ]);

        $product->refresh();
        $this->assertSame('Gaseosa Colombiana', $product->name);
        $this->assertSame(15, $product->stock);
        $this->assertSame('1200.00', $product->cost_price);
        $this->assertSame('2500.00', $product->price);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_ADJUSTMENT,
            'quantity' => 5,
            'notes' => 'Conteo fisico',
        ]);
        $this->assertDatabaseHas('product_cost_history', [
            'product_id' => $product->id,
            'cost_before' => '1000.0000',
            'cost_after' => '1200.0000',
            'source' => ProductCostHistory::SOURCE_MANUAL_ADJUSTMENT,
        ]);
    }

    public function test_bulk_update_ignores_fields_that_did_not_change(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create([
            'business_id' => $admin->business_id,
            'name' => 'Gaseosa',
            'stock' => 10,
            'price' => 2000,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/products/bulk-update', [
            'items' => [
                ['product_id' => $product->id, 'new_stock' => 10],
            ],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_bulk_update_rejects_single_sale_and_service_products(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create([
            'business_id' => $admin->business_id,
            'is_single_sale' => true,
            'stock' => 1,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/products/bulk-update', [
            'items' => [
                ['product_id' => $product->id, 'new_stock' => 2],
            ],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_bulk_update_only_touches_products_of_the_authenticated_business(): void
    {
        $admin = $this->admin();
        $other = Product::factory()->create(['stock' => 5]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/products/bulk-update', [
            'items' => [
                ['product_id' => $other->id, 'new_stock' => 20],
            ],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
    }

    public function test_bulk_update_applies_ingredient_name_and_stock_changes(): void
    {
        $admin = $this->admin();
        $ingredient = Ingredient::factory()->create([
            'business_id' => $admin->business_id,
            'name' => 'Harina',
            'stock' => 5,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ingredients/bulk-update', [
            'items' => [
                ['ingredient_id' => $ingredient->id, 'new_name' => 'Harina de trigo', 'new_stock' => 8],
            ],
        ]);

        $response->assertOk()->assertJson(['name_count' => 1, 'stock_count' => 1]);

        $ingredient->refresh();
        $this->assertSame('Harina de trigo', $ingredient->name);
        $this->assertSame('8.00', $ingredient->stock);
    }
}
