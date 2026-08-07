<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cubre purchase_lines.ingredient_id: una compra puede traer lineas de
 * ingrediente ademas de (o en vez de) lineas de producto - ultimo hueco del
 * retrofit de recetas (ver docs/MIGRATION_BACKLOG.md). No prueba de nuevo la
 * logica de lineas de producto, ya cubierta por PurchaseTest.
 */
class PurchaseIngredientLineTest extends TestCase
{
    use DatabaseTransactions;

    private function businessAndUser(): array
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        return [$business, $user];
    }

    public function test_registering_a_purchase_with_an_ingredient_line_adds_stock_and_updates_weighted_average_cost(): void
    {
        [$business, $user] = $this->businessAndUser();
        $flour = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 10, 'cost_price' => 1000]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'lines' => [
                ['ingredient_id' => $flour->id, 'quantity' => 10, 'line_total_cop' => 20000],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('total', 20000)
            ->assertJsonCount(1, 'lines')
            ->assertJsonPath('lines.0.ingredient.id', $flour->id);

        // stock 10 @1000 + 10 @2000 -> promedio ponderado 1500.
        $flour->refresh();
        $this->assertSame('20.00', $flour->stock);
        $this->assertEquals(1500, (float) $flour->cost_price);

        $lineId = $response->json('lines.0.id');
        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $flour->id,
            'purchase_line_id' => $lineId,
            'type' => StockMovement::TYPE_ENTRY,
            'quantity' => 10,
            'unit_cost_cop' => 2000,
        ]);
        $this->assertDatabaseMissing('stock_movements', ['purchase_line_id' => $lineId, 'product_id' => null, 'ingredient_id' => null]);
    }

    public function test_an_ingredient_line_accepts_a_fractional_quantity(): void
    {
        [$business, $user] = $this->businessAndUser();
        $oil = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 0, 'cost_price' => 0]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'lines' => [['ingredient_id' => $oil->id, 'quantity' => 2.5, 'line_total_cop' => 12500]],
        ])->assertCreated();

        $this->assertSame('2.50', $oil->fresh()->stock);
    }

    public function test_a_purchase_can_mix_product_and_ingredient_lines(): void
    {
        [$business, $user] = $this->businessAndUser();
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 0]);
        $sugar = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 0]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 5, 'line_total_cop' => 5000],
                ['ingredient_id' => $sugar->id, 'quantity' => 3, 'line_total_cop' => 3000],
            ],
        ]);

        $response->assertCreated()->assertJsonCount(2, 'lines');
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame('3.00', $sugar->fresh()->stock);
    }

    public function test_a_line_with_both_product_and_ingredient_is_rejected(): void
    {
        [$business, $user] = $this->businessAndUser();
        $product = Product::factory()->create(['business_id' => $business->id]);
        $ingredient = Ingredient::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id, 'ingredient_id' => $ingredient->id,
                'quantity' => 1, 'line_total_cop' => 1000,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0');
    }

    public function test_a_line_with_neither_product_nor_ingredient_is_rejected(): void
    {
        [$business, $user] = $this->businessAndUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'lines' => [['quantity' => 1, 'line_total_cop' => 1000]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0');
    }

    public function test_purchasing_a_product_that_uses_a_recipe_is_rejected(): void
    {
        [$business, $user] = $this->businessAndUser();
        $ingredient = Ingredient::factory()->create(['business_id' => $business->id]);
        $burger = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true]);
        $burger->ingredients()->attach($ingredient->id, ['quantity' => 1]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'lines' => [['product_id' => $burger->id, 'quantity' => 1, 'line_total_cop' => 1000]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.product_id');
    }

    public function test_ingredient_line_propagates_the_new_cost_to_linked_products(): void
    {
        [$business, $user] = $this->businessAndUser();
        $flour = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 0, 'cost_price' => 0]);
        $bread = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true]);
        $bread->ingredients()->attach($flour->id, ['quantity' => 2]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'lines' => [['ingredient_id' => $flour->id, 'quantity' => 10, 'line_total_cop' => 10000]],
        ])->assertCreated();

        // flour cost_price pasa a 1000 (0 previo -> promedio = costo entrante);
        // receta = 2 * 1000 = 2000.
        $this->assertSame('2000.00', $bread->fresh()->cost_price);
    }
}
