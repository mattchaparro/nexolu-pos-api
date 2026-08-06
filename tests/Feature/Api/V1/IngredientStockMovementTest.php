<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockMovementReason;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IngredientStockMovementTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_entry_increases_ingredient_stock(): void
    {
        $admin = $this->admin();
        $ingredient = Ingredient::factory()->create(['business_id' => $admin->business_id, 'stock' => 10]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ingredient-stock-movements', [
            'ingredient_id' => $ingredient->id,
            'type' => 'entry',
            'quantity' => 5,
            'notes' => 'Reposicion',
        ]);

        $response->assertCreated()
            ->assertJsonPath('type', 'entry')
            ->assertJsonPath('quantity', 5)
            ->assertJsonPath('ingredient_id', $ingredient->id)
            ->assertJsonPath('reason.code', 'manual_in');

        $this->assertSame('15.00', (string) $ingredient->fresh()->stock);
    }

    public function test_exit_decreases_ingredient_stock(): void
    {
        $admin = $this->admin();
        $ingredient = Ingredient::factory()->create(['business_id' => $admin->business_id, 'stock' => 10]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ingredient-stock-movements', [
            'ingredient_id' => $ingredient->id,
            'type' => 'exit',
            'quantity' => 4,
            'stock_movement_reason_id' => StockMovementReason::systemIdForCode('waste'),
        ])->assertCreated()->assertJsonPath('quantity', -4);

        $this->assertSame('6.00', (string) $ingredient->fresh()->stock);
    }

    public function test_adjustment_sets_stock_to_target_value(): void
    {
        $admin = $this->admin();
        $ingredient = Ingredient::factory()->create(['business_id' => $admin->business_id, 'stock' => 10]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ingredient-stock-movements', [
            'ingredient_id' => $ingredient->id,
            'type' => 'adjustment',
            'quantity' => 3,
        ])->assertCreated()->assertJsonPath('quantity', -7);

        $this->assertSame('3.00', (string) $ingredient->fresh()->stock);
    }

    public function test_entry_with_unit_cost_recalculates_weighted_average_and_propagates_to_recipes(): void
    {
        $admin = $this->admin();
        $ingredient = Ingredient::factory()->create(['business_id' => $admin->business_id, 'stock' => 10, 'cost_price' => 1000]);
        $product = Product::factory()->create(['business_id' => $admin->business_id]);
        $product->ingredients()->attach($ingredient->id, ['quantity' => 1]);

        // (10 * 1000 + 10 * 2000) / 20 = 1500
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ingredient-stock-movements', [
            'ingredient_id' => $ingredient->id,
            'type' => 'entry',
            'quantity' => 10,
            'unit_cost_cop' => 2000,
        ])->assertCreated();

        $this->assertSame('1500.0000', (string) $ingredient->fresh()->cost_price);
        $this->assertSame('1500.00', (string) $product->fresh()->cost_price);
    }

    public function test_entry_without_unit_cost_does_not_change_cost_price(): void
    {
        $admin = $this->admin();
        $ingredient = Ingredient::factory()->create(['business_id' => $admin->business_id, 'stock' => 10, 'cost_price' => 1000]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ingredient-stock-movements', [
            'ingredient_id' => $ingredient->id,
            'type' => 'entry',
            'quantity' => 5,
        ])->assertCreated();

        $this->assertSame('1000.0000', (string) $ingredient->fresh()->cost_price);
    }

    public function test_index_filters_by_ingredient_and_excludes_product_movements(): void
    {
        $admin = $this->admin();
        $ingredient = Ingredient::factory()->create(['business_id' => $admin->business_id]);
        $product = Product::factory()->create(['business_id' => $admin->business_id]);

        StockMovement::create([
            'ingredient_id' => $ingredient->id, 'business_id' => $admin->business_id,
            'type' => 'entry', 'quantity' => 5, 'user_id' => $admin->id,
        ]);
        StockMovement::create([
            'product_id' => $product->id, 'business_id' => $admin->business_id,
            'type' => 'entry', 'quantity' => 5, 'user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/ingredient-stock-movements');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($ingredient->id, $response->json('data.0.ingredient_id'));
    }

    public function test_cannot_move_stock_for_another_business_ingredient(): void
    {
        $admin = $this->admin();
        $other = Ingredient::factory()->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ingredient-stock-movements', [
            'ingredient_id' => $other->id,
            'type' => 'entry',
            'quantity' => 5,
        ])->assertUnprocessable();
    }
}
