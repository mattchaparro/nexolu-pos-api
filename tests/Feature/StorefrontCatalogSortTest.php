<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Ordenamiento y contenido de la ficha en el catalogo publico.
 */
class StorefrontCatalogSortTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    private function storefrontBusiness(): Business
    {
        $business = Business::factory()->create(['feature_flags' => ['online_store' => true]]);

        BusinessStoreSettings::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
        ]);

        return $business;
    }

    private function publishedProduct(Business $business, array $attributes = []): Product
    {
        return Product::factory()->create([
            'business_id' => $business->id,
            'is_published' => true,
            'is_active' => true,
            'is_service' => false,
            ...$attributes,
        ]);
    }

    /** @return list<string> */
    private function names(Business $business, string $sort): array
    {
        $response = $this->getJson("/api/v1/storefront/{$business->slug}/products?sort={$sort}");
        $response->assertOk();

        return array_column($response->json('data'), 'name');
    }

    public function test_ordena_por_precio_ascendente_y_descendente(): void
    {
        $business = $this->storefrontBusiness();
        $this->publishedProduct($business, ['name' => 'Caro', 'price' => 90000]);
        $this->publishedProduct($business, ['name' => 'Barato', 'price' => 1000]);
        $this->publishedProduct($business, ['name' => 'Medio', 'price' => 50000]);

        $this->assertSame(['Barato', 'Medio', 'Caro'], $this->names($business, 'price_asc'));
        $this->assertSame(['Caro', 'Medio', 'Barato'], $this->names($business, 'price_desc'));
    }

    /**
     * El caso que motivo la subconsulta: un producto con variantes publica el
     * MINIMO de sus variantes, no `products.price`. Si el orden usara la
     * columna del producto, esta lista saldria al reves de lo que se ve.
     */
    public function test_ordena_por_el_precio_que_se_publica_no_por_el_del_producto(): void
    {
        $business = $this->storefrontBusiness();

        // products.price alto, pero su variante mas barata cuesta 500. "Tener
        // variantes" no es una columna: es que existan filas en
        // product_variants (ver StorefrontProductResource).
        $conVariantes = $this->publishedProduct($business, [
            'name' => 'Con variantes',
            'price' => 99000,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $conVariantes->id,
            'business_id' => $business->id,
            'price' => 500,
            'is_active' => true,
        ]);

        $this->publishedProduct($business, ['name' => 'Simple', 'price' => 20000]);

        // 500 < 20000: el de variantes va primero.
        $this->assertSame(['Con variantes', 'Simple'], $this->names($business, 'price_asc'));
    }

    public function test_un_sort_desconocido_cae_en_el_orden_por_nombre(): void
    {
        $business = $this->storefrontBusiness();
        $this->publishedProduct($business, ['name' => 'Zeta', 'price' => 1000]);
        $this->publishedProduct($business, ['name' => 'Alfa', 'price' => 9000]);

        // Un valor arbitrario no puede alterar la consulta ni reventarla.
        $this->assertSame(['Alfa', 'Zeta'], $this->names($business, 'precio); DROP TABLE products;--'));
    }

    public function test_la_ficha_publica_incluye_como_se_usa(): void
    {
        $business = $this->storefrontBusiness();
        $product = $this->publishedProduct($business, [
            'how_to_use' => 'Aplicar sobre la piel seca.',
            'online_description' => 'Crema hidratante',
        ]);

        $response = $this->getJson("/api/v1/storefront/{$business->slug}/products/{$product->id}");

        $response->assertOk();
        $response->assertJsonPath('how_to_use', 'Aplicar sobre la piel seca.');
        $response->assertJsonPath('description', 'Crema hidratante');
    }
}
