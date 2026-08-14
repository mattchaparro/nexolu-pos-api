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
 * Cubre el retrofit del flujo de ventas para productos con receta: al
 * vender, se descuenta el stock de cada ingrediente (no products.stock, que
 * es una columna "fantasma" para estos productos) y la disponibilidad se
 * valida contra ProductAvailability::effectiveStock(), no contra el stock
 * crudo del producto.
 */
class RecipeSaleTest extends TestCase
{
    use DatabaseTransactions;

    private function businessAndUser(): array
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        return [$business, $user];
    }

    private function recipeProduct(Business $business, array $ingredientsWithQuantity): Product
    {
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'track_stock' => true,
            'stock' => 999,
        ]);

        foreach ($ingredientsWithQuantity as [$ingredient, $quantity]) {
            $product->ingredients()->attach($ingredient->id, ['quantity' => $quantity]);
        }

        return $product;
    }

    public function test_selling_a_recipe_product_deducts_each_ingredients_stock_and_not_products_stock(): void
    {
        [$business, $user] = $this->businessAndUser();
        $bread = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 20]);
        $cheese = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 10]);
        $burger = $this->recipeProduct($business, [[$bread, 2], [$cheese, 1]]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $burger->id, 'quantity' => 3]],
        ]);

        $response->assertCreated();

        // products.stock nunca se toca para un producto con receta.
        $this->assertSame(999, $burger->fresh()->stock);
        $this->assertSame('14.00', (string) $bread->fresh()->stock);
        $this->assertSame('7.00', (string) $cheese->fresh()->stock);

        $this->assertDatabaseMissing('stock_movements', ['product_id' => $burger->id]);
        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $bread->id, 'type' => StockMovement::TYPE_EXIT, 'quantity' => -6,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $cheese->id, 'type' => StockMovement::TYPE_EXIT, 'quantity' => -3,
        ]);
    }

    public function test_selling_a_recipe_product_is_rejected_when_the_scarcest_ingredient_runs_short(): void
    {
        [$business, $user] = $this->businessAndUser();
        $bread = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 20]);
        $cheese = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 2]);
        $burger = $this->recipeProduct($business, [[$bread, 1], [$cheese, 1]]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $burger->id, 'quantity' => 3]],
        ]);

        $response->assertUnprocessable();
        $this->assertSame('20.00', $bread->fresh()->stock);
        $this->assertSame('2.00', $cheese->fresh()->stock);
    }

    public function test_reversing_a_sale_of_a_recipe_product_restores_ingredient_stock(): void
    {
        [$business, $user] = $this->businessAndUser();
        $user->syncPermissions(['sales.reverse']);
        $bread = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 20]);
        $burger = $this->recipeProduct($business, [[$bread, 2]]);

        $sale = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $burger->id, 'quantity' => 2]],
        ])->json();

        $this->assertSame('16.00', $bread->fresh()->stock);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/reverse")->assertNoContent();

        $this->assertSame('20.00', $bread->fresh()->stock);
        $this->assertDatabaseMissing('sales', ['id' => $sale['id']]);
    }

    public function test_a_product_without_a_recipe_still_uses_its_own_stock_when_ingredients_feature_is_active(): void
    {
        [$business, $user] = $this->businessAndUser();
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 10]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated();

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'quantity' => -3]);
    }

    public function test_a_recipe_is_ignored_when_the_business_has_the_ingredients_feature_disabled(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['ingredients' => false]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $ingredient = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 20]);
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 10]);
        $product->ingredients()->attach($ingredient->id, ['quantity' => 5]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        // Sin el feature, se ignora la receta: se mueve products.stock normal.
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame('20.00', $ingredient->fresh()->stock);
    }

    public function test_opening_a_tab_with_a_recipe_product_deducts_ingredients(): void
    {
        [$business, $user] = $this->businessAndUser();
        $ingredient = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 20]);
        $pizza = $this->recipeProduct($business, [[$ingredient, 3]]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $pizza->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->assertSame('14.00', $ingredient->fresh()->stock);
    }

    public function test_syncing_tab_items_adjusts_ingredient_stock_by_delta(): void
    {
        [$business, $user] = $this->businessAndUser();
        $ingredient = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 20]);
        $pizza = $this->recipeProduct($business, [[$ingredient, 2]]);

        $open = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $pizza->id, 'quantity' => 2]],
        ])->json();
        $this->assertSame('16.00', $ingredient->fresh()->stock);

        // Sube de 2 a 5 unidades: delta +3 -> consume 6 mas del ingrediente.
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/open-tabs/{$open['id']}/items", [
            'items' => [['product_id' => $pizza->id, 'quantity' => 5]],
        ])->assertOk();
        $this->assertSame('10.00', $ingredient->fresh()->stock);

        // Baja de 5 a 1: delta -4 -> restaura 8.
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/open-tabs/{$open['id']}/items", [
            'items' => [['product_id' => $pizza->id, 'quantity' => 1]],
        ])->assertOk();
        $this->assertSame('18.00', $ingredient->fresh()->stock);
    }

    public function test_cancelling_a_tab_with_a_recipe_product_restores_ingredient_stock(): void
    {
        [$business, $user] = $this->businessAndUser();
        $user->syncPermissions(['sales.reverse']);
        $ingredient = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 20]);
        $pizza = $this->recipeProduct($business, [[$ingredient, 3]]);

        $open = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $pizza->id, 'quantity' => 2]],
        ])->json();
        $this->assertSame('14.00', $ingredient->fresh()->stock);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/open-tabs/{$open['id']}")->assertNoContent();

        $this->assertSame('20.00', $ingredient->fresh()->stock);
    }
}
