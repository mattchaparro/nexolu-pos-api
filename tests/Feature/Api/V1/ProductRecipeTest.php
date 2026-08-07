<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cubre el CRUD de la receta de un producto (ingredient_product): se
 * gestiona como parte del payload de POST/PUT /v1/products, no via un
 * endpoint aparte - igual que el legacy. Ver Product::ingredients(),
 * ProductService::syncIngredients() y las reglas en Store/UpdateProductRequest.
 */
class ProductRecipeTest extends TestCase
{
    use DatabaseTransactions;

    private function adminForNewBusiness(array $businessAttributes = []): array
    {
        $business = Business::factory()->create($businessAttributes);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        return [$business, $user];
    }

    public function test_creating_a_product_with_ingredients_attaches_the_recipe_and_forces_track_stock(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $bread = Ingredient::factory()->create(['business_id' => $business->id, 'cost_price' => 500]);
        $cheese = Ingredient::factory()->create(['business_id' => $business->id, 'cost_price' => 300]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Hamburguesa',
            'price' => 15000,
            'category_id' => $category->id,
            'track_stock' => false,
            'ingredients' => [
                ['ingredient_id' => $bread->id, 'quantity' => 2],
                ['ingredient_id' => $cheese->id, 'quantity' => 1],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('track_stock', true)
            ->assertJsonPath('has_recipe', true)
            ->assertJsonCount(2, 'ingredients');

        $product = Product::where('name', 'Hamburguesa')->first();
        $this->assertDatabaseHas('ingredient_product', [
            'product_id' => $product->id, 'ingredient_id' => $bread->id, 'quantity' => 2,
        ]);
        // cost_price se recalcula desde la receta: 2*500 + 1*300 = 1300.
        $this->assertSame('1300.00', $product->cost_price);
    }

    public function test_creating_a_service_with_ingredients_is_rejected(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $ingredient = Ingredient::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Corte de cabello',
            'price' => 20000,
            'category_id' => $category->id,
            'is_service' => true,
            'ingredients' => [['ingredient_id' => $ingredient->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('ingredients');
    }

    public function test_creating_a_single_sale_product_with_ingredients_is_rejected(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $ingredient = Ingredient::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Perfume unico',
            'price' => 90000,
            'category_id' => $category->id,
            'is_single_sale' => true,
            'stock' => 1,
            'ingredients' => [['ingredient_id' => $ingredient->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('ingredients');
    }

    public function test_an_ingredient_from_another_business_is_rejected(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $foreignIngredient = Ingredient::factory()->create(['business_id' => Business::factory()->create()->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Producto',
            'price' => 1000,
            'category_id' => $category->id,
            'ingredients' => [['ingredient_id' => $foreignIngredient->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('ingredients.0.ingredient_id');
    }

    public function test_updating_a_products_ingredients_replaces_the_recipe(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        $bread = Ingredient::factory()->create(['business_id' => $business->id]);
        $cheese = Ingredient::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);
        $product->ingredients()->attach($bread->id, ['quantity' => 1]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$product->id}", [
            'ingredients' => [['ingredient_id' => $cheese->id, 'quantity' => 3]],
        ])->assertOk()->assertJsonCount(1, 'ingredients');

        $this->assertDatabaseMissing('ingredient_product', ['product_id' => $product->id, 'ingredient_id' => $bread->id]);
        $this->assertDatabaseHas('ingredient_product', ['product_id' => $product->id, 'ingredient_id' => $cheese->id, 'quantity' => 3]);
    }

    public function test_omitting_ingredients_on_update_leaves_the_existing_recipe_untouched(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        $bread = Ingredient::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 1000]);
        $product->ingredients()->attach($bread->id, ['quantity' => 1]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$product->id}", [
            'price' => 2000,
        ])->assertOk();

        $this->assertDatabaseHas('ingredient_product', ['product_id' => $product->id, 'ingredient_id' => $bread->id]);
    }

    public function test_sending_an_empty_ingredients_array_clears_the_recipe(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        $bread = Ingredient::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);
        $product->ingredients()->attach($bread->id, ['quantity' => 1]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$product->id}", [
            'ingredients' => [],
        ])->assertOk()->assertJsonPath('has_recipe', false);

        $this->assertDatabaseMissing('ingredient_product', ['product_id' => $product->id]);
    }

    public function test_switching_a_recipe_product_to_service_without_clearing_ingredients_is_rejected(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        $bread = Ingredient::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);
        $product->ingredients()->attach($bread->id, ['quantity' => 1]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$product->id}", [
            'is_service' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('ingredients');
    }

    public function test_clearing_ingredients_and_switching_to_single_sale_in_the_same_request_is_allowed(): void
    {
        // Mejora sobre el legacy: legacy validaba solo contra el estado YA
        // guardado del producto, asi que quitar la receta y activar
        // is_single_sale en la misma request quedaba bloqueado sin razon.
        [$business, $user] = $this->adminForNewBusiness();
        $bread = Ingredient::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 1]);
        $product->ingredients()->attach($bread->id, ['quantity' => 1]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$product->id}", [
            'is_single_sale' => true,
            'ingredients' => [],
        ])->assertOk()->assertJsonPath('has_recipe', false);
    }

    public function test_ingredients_are_ignored_when_the_business_has_the_feature_disabled(): void
    {
        [$business, $user] = $this->adminForNewBusiness(['feature_flags' => ['ingredients' => false]]);
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $ingredient = Ingredient::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Producto',
            'price' => 1000,
            'category_id' => $category->id,
            'ingredients' => [['ingredient_id' => $ingredient->id, 'quantity' => 1]],
        ]);

        $response->assertCreated();
        $product = Product::where('name', 'Producto')->first();
        $this->assertDatabaseMissing('ingredient_product', ['product_id' => $product->id]);
    }

    public function test_index_and_show_expose_the_recipe_only_when_the_feature_is_enabled(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        $bread = Ingredient::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);
        $product->ingredients()->attach($bread->id, ['quantity' => 2]);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('has_recipe', true)
            ->assertJsonPath('ingredients.0.quantity', '2.000');
    }
}
