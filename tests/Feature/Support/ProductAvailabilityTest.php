<?php

namespace Tests\Feature\Support;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Support\ProductAvailability;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductAvailabilityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_product_without_track_stock_is_always_available(): void
    {
        $product = Product::factory()->create(['track_stock' => false, 'stock' => 0]);

        $this->assertInfinite(ProductAvailability::effectiveStock($product, true));
    }

    public function test_a_product_without_a_recipe_uses_its_own_stock(): void
    {
        $product = Product::factory()->create(['track_stock' => true, 'stock' => 7]);
        $product->load('ingredients');

        $this->assertSame(7.0, ProductAvailability::effectiveStock($product, true));
    }

    public function test_a_recipe_products_availability_is_limited_by_the_scarcest_ingredient(): void
    {
        $business = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 999]);
        $bread = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 20]);
        $cheese = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 6]);
        $product->ingredients()->attach($bread->id, ['quantity' => 2]);
        $product->ingredients()->attach($cheese->id, ['quantity' => 1]);

        // pan: floor(20/2) = 10 hamburguesas; queso: floor(6/1) = 6 -> el minimo gana.
        $product->load('ingredients');
        $this->assertSame(6.0, ProductAvailability::effectiveStock($product, true));
    }

    public function test_ignores_the_recipe_when_the_ingredients_feature_is_disabled(): void
    {
        $business = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 50]);
        $ingredient = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 1]);
        $product->ingredients()->attach($ingredient->id, ['quantity' => 1]);
        $product->load('ingredients');

        $this->assertSame(50.0, ProductAvailability::effectiveStock($product, false));
    }

    public function test_a_pivot_quantity_of_zero_does_not_limit_availability(): void
    {
        $business = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 999]);
        $freeIngredient = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 0]);
        $limiting = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 4]);
        $product->ingredients()->attach($freeIngredient->id, ['quantity' => 0]);
        $product->ingredients()->attach($limiting->id, ['quantity' => 1]);
        $product->load('ingredients');

        $this->assertSame(4.0, ProductAvailability::effectiveStock($product, true));
    }
}
