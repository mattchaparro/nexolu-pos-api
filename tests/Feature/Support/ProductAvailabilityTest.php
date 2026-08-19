<?php

namespace Tests\Feature\Support;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductAvailability;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
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

    // --- forBusiness(): catalogo completo para Vender ---

    public function test_for_business_returns_the_full_catalog_beyond_the_pagination_cap_of_index(): void
    {
        $business = Business::factory()->create();
        Product::factory()->count(210)->create(['business_id' => $business->id]);

        $this->assertCount(210, ProductAvailability::forBusiness($business));
    }

    public function test_for_business_excludes_inactive_products(): void
    {
        $business = Business::factory()->create();
        $active = Product::factory()->create(['business_id' => $business->id, 'is_active' => true]);
        Product::factory()->create(['business_id' => $business->id, 'is_active' => false]);

        $result = ProductAvailability::forBusiness($business);

        $this->assertCount(1, $result);
        $this->assertSame($active->id, $result->first()->id);
    }

    public function test_for_business_is_cached(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['ingredients' => false]]);
        Product::factory()->create(['business_id' => $business->id]);

        $this->assertFalse(Cache::has(ProductAvailability::cacheKey($business->id)));
        ProductAvailability::forBusiness($business);
        $this->assertTrue(Cache::has(ProductAvailability::cacheKey($business->id)));
    }

    public function test_for_business_skips_the_cache_when_the_ingredients_feature_is_enabled(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['ingredients' => true]]);
        Product::factory()->create(['business_id' => $business->id]);

        ProductAvailability::forBusiness($business);

        $this->assertFalse(Cache::has(ProductAvailability::cacheKey($business->id)));
    }

    public function test_clear_cache_forgets_the_cached_catalog(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['ingredients' => false]]);
        Product::factory()->create(['business_id' => $business->id]);
        ProductAvailability::forBusiness($business);
        $this->assertTrue(Cache::has(ProductAvailability::cacheKey($business->id)));

        ProductAvailability::clearCache($business->id);

        $this->assertFalse(Cache::has(ProductAvailability::cacheKey($business->id)));
    }

    /** Guardar un producto invalida el cache - un precio nuevo no puede tardar hasta 10 min en verse en Vender. */
    public function test_saving_a_product_invalidates_the_cached_catalog(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['ingredients' => false]]);
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Nombre viejo']);
        ProductAvailability::forBusiness($business);
        $this->assertTrue(Cache::has(ProductAvailability::cacheKey($business->id)));

        $product->update(['name' => 'Nombre nuevo']);

        $this->assertFalse(Cache::has(ProductAvailability::cacheKey($business->id)));
        $this->assertSame('Nombre nuevo', ProductAvailability::forBusiness($business)->first()->name);
    }

    /**
     * Bug real: config/cache.php fija serializable_classes=false (bloquea
     * deserializar objetos PHP arbitrarios - proteccion contra inyeccion de
     * objetos si un valor de cache se corrompe/manipula). Con esa bandera,
     * unserialize() convierte CUALQUIER objeto en __PHP_Incomplete_Class -
     * cachear un Collection<Product> crudo se rompe en la siguiente lectura
     * (TypeError, reproducible con Cache::put()+Cache::get() directo). Los
     * tests corren con CACHE_STORE=array (ver phpunit.xml), que nunca
     * serializa de verdad y no detectaba esto - por eso se fuerza aca el
     * store 'database' real para probar el round-trip completo (escribir Y
     * releer desde cache), no solo el primer miss.
     */
    public function test_for_business_survives_a_real_database_cache_round_trip(): void
    {
        config(['cache.default' => 'database']);
        $business = Business::factory()->create(['feature_flags' => ['ingredients' => false]]);
        $product = Product::factory()->create(['business_id' => $business->id]);
        Cache::store('database')->forget(ProductAvailability::cacheKey($business->id));

        ProductAvailability::forBusiness($business); // cache miss: escribe

        $fromCache = ProductAvailability::forBusiness($business); // cache hit: lee

        $this->assertCount(1, $fromCache);
        $this->assertSame($product->id, $fromCache->first()->id);
        $this->assertTrue($fromCache->first()->relationLoaded('category'));
    }

    /** El stock cambia por SQL directo (increment), que no dispara el evento saved de Product. */
    public function test_a_stock_movement_invalidates_the_cached_catalog(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['ingredients' => false]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);
        ProductAvailability::forBusiness($business);
        $this->assertTrue(Cache::has(ProductAvailability::cacheKey($business->id)));

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/stock-movements', [
            'product_id' => $product->id,
            'type' => 'entry',
            'quantity' => 5,
        ])->assertCreated();

        $this->assertFalse(Cache::has(ProductAvailability::cacheKey($business->id)));
        $this->assertSame(15.0, (float) ProductAvailability::forBusiness($business)->first()->stock);
    }
}
