<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\BusinessFeaturePresets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Un plato con receta descuenta sus insumos y no `products.stock`, que se
 * queda quieto (en 0 o negativo). La carta y el pedido tienen que mirar los
 * insumos, como Vender: si no, todo plato con receta sale "Agotado".
 */
class StorefrontRecipeStockTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    /** @return array{0: Business, 1: Product, 2: Ingredient} */
    private function dishWithRecipe(float $breadStock, array $flags = []): array
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [
                ...BusinessFeaturePresets::full(),
                'online_store' => false,
                'digital_menu' => true,
                'kitchen_board' => false,
                ...$flags,
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
            'track_stock' => true,
            'stock' => -32,
            'price' => 15000,
        ]);
        $bread = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => $breadStock]);
        $dish->ingredients()->attach($bread->id, ['quantity' => 1]);

        return [$business, $dish, $bread];
    }

    private function order(Business $business, Product $dish, int $quantity)
    {
        return $this->postJson("/api/v1/storefront/{$business->slug}/orders", [
            'customer_name' => 'Ana',
            'customer_phone' => '3001234567',
            'is_pickup' => true,
            'items' => [['product_id' => $dish->id, 'quantity' => $quantity]],
        ]);
    }

    public function test_a_dish_with_ingredients_in_stock_is_available_and_can_be_ordered(): void
    {
        [$business, $dish] = $this->dishWithRecipe(breadStock: 120);

        $this->getJson("/api/v1/storefront/{$business->slug}/products")
            ->assertOk()
            ->assertJsonPath('data.0.available.in_stock', true);
        $this->getJson("/api/v1/storefront/{$business->slug}/products/{$dish->id}")
            ->assertOk()
            ->assertJsonPath('available.in_stock', true);
        $this->getJson("/api/v1/storefront/{$business->slug}/products?in_stock=1")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->order($business, $dish, 2)->assertCreated();
    }

    public function test_a_dish_without_ingredients_left_is_sold_out_and_cannot_be_ordered(): void
    {
        [$business, $dish] = $this->dishWithRecipe(breadStock: 1);

        $this->order($business, $dish, 2)->assertUnprocessable();

        // Con el único pan apartado por un pedido pendiente ya no queda.
        $this->order($business, $dish, 1)->assertCreated();
        $this->getJson("/api/v1/storefront/{$business->slug}/products")
            ->assertOk()
            ->assertJsonPath('data.0.available.in_stock', false);
    }

    public function test_without_the_ingredients_module_the_dish_uses_its_own_stock(): void
    {
        [$business, $dish] = $this->dishWithRecipe(breadStock: 120, flags: ['ingredients' => false]);

        $this->getJson("/api/v1/storefront/{$business->slug}/products")
            ->assertOk()
            ->assertJsonPath('data.0.available.in_stock', false);
        $this->order($business, $dish, 1)->assertUnprocessable();
    }
}
