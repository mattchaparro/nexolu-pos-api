<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guardas contra el N+1 que Sentry encontro en produccion (oct-2026): un
 * exists() de variantes por cada producto en /open-tabs y en
 * /products/sellable. Las dos pantallas se piden cada vez que cambia una
 * cuenta o una venta, asi que el costo por fila se multiplicaba por mesas
 * abiertas y por tablets. El numero de consultas no debe crecer con el
 * numero de productos.
 */
class ListingQueryCountTest extends TestCase
{
    use DatabaseTransactions;

    public function test_open_tabs_listing_does_not_query_once_per_item(): void
    {
        [$business, $user] = $this->businessWithUser();
        $this->openTabWith($business, $user, 2);
        $this->getJson('/api/v1/open-tabs')->assertOk(); // calentamiento: permisos, sesion

        $few = $this->countQueries(fn () => $this->getJson('/api/v1/open-tabs')->assertOk());
        $this->openTabWith($business, $user, 6);
        $many = $this->countQueries(fn () => $this->getJson('/api/v1/open-tabs')->assertOk());

        // Una cuenta mas agrega sus propias lineas, pero las consultas no
        // dependen de cuantas haya: se cargan todas juntas.
        $this->assertSame($few, $many, "GET /open-tabs: {$few} consultas con 2 items, {$many} con 8");
    }

    public function test_sellable_catalog_does_not_query_once_per_product(): void
    {
        // Sin variantes ni insumos: es el camino donde las variantes no se
        // cargan y has_variants se resolvia con un exists() por producto.
        [$business] = $this->businessWithUser(['variants' => false, 'ingredients' => false]);
        Product::factory()->count(2)->create(['business_id' => $business->id, 'stock' => 50, 'is_active' => true]);
        $this->getJson('/api/v1/products/sellable')->assertOk(); // calentamiento

        Cache::flush();
        $few = $this->countQueries(fn () => $this->getJson('/api/v1/products/sellable')->assertOk());
        Product::factory()->count(5)->create(['business_id' => $business->id, 'stock' => 50, 'is_active' => true]);
        Cache::flush();
        $many = $this->countQueries(fn () => $this->getJson('/api/v1/products/sellable')->assertOk());

        $this->assertSame($few, $many, "GET /products/sellable: {$few} consultas con 2 productos, {$many} con 7");
    }

    public function test_has_variants_is_still_reported_without_the_variants_module(): void
    {
        [$business, $user] = $this->businessWithUser(['variants' => false, 'ingredients' => false, 'open_tabs' => true]);
        $plain = Product::factory()->create(['business_id' => $business->id, 'stock' => 10, 'is_active' => true]);
        $withVariants = Product::factory()->create(['business_id' => $business->id, 'stock' => 10, 'is_active' => true]);
        ProductVariant::factory()->create(['product_id' => $withVariants->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $plain->id, 'quantity' => 1]],
        ])->assertCreated();

        $catalog = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/products/sellable')->assertOk()->json())
            ->keyBy('id');
        $this->assertFalse($catalog[$plain->id]['has_variants']);
        $this->assertTrue($catalog[$withVariants->id]['has_variants']);

        // Segunda lectura: sale del cache de forBusiness() y debe decir lo mismo.
        $cached = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/products/sellable')->json())->keyBy('id');
        $this->assertTrue($cached[$withVariants->id]['has_variants']);

        $tabs = $this->actingAs($user, 'sanctum')->getJson('/api/v1/open-tabs')->assertOk();
        $this->assertFalse($tabs->json('data.0.items.0.product.has_variants') ?? $tabs->json('0.items.0.product.has_variants'));
    }

    /**
     * @param  array<string, bool>|null  $featureFlags
     * @return array{0: Business, 1: User}
     */
    private function businessWithUser(?array $featureFlags = null): array
    {
        $business = Business::factory()->create(['feature_flags' => $featureFlags]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $this->actingAs($user, 'sanctum');

        return [$business, $user];
    }

    private function openTabWith(Business $business, User $user, int $itemCount): void
    {
        $items = Product::factory()->count($itemCount)->create(['business_id' => $business->id, 'stock' => 50])
            ->map(fn (Product $product) => ['product_id' => $product->id, 'quantity' => 1])
            ->all();

        $this->postJson('/api/v1/open-tabs', ['items' => $items])->assertCreated();
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
