<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Product;
use App\Models\ProductCategory;
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

    /**
     * El caso normal de una tienda con subcategorias: el padre agrupa y no
     * tiene productos propios. Si no viniera, sus hijas quedarian huerfanas.
     */
    public function test_una_categoria_padre_sin_productos_propios_igual_aparece(): void
    {
        $business = $this->storefrontBusiness();

        $padre = ProductCategory::factory()->create([
            'business_id' => $business->id,
            'name' => 'Bebidas',
            'is_published' => true,
            'parent_id' => null,
        ]);
        $hija = ProductCategory::factory()->create([
            'business_id' => $business->id,
            'name' => 'Sodas',
            'is_published' => true,
            'parent_id' => $padre->id,
        ]);

        // El producto cuelga SOLO de la hija.
        $this->publishedProduct($business, ['category_id' => $hija->id]);

        $response = $this->getJson("/api/v1/storefront/{$business->slug}/categories");

        $response->assertOk();
        $nombres = array_column($response->json(), 'name');
        $this->assertContains('Bebidas', $nombres);
        $this->assertContains('Sodas', $nombres);
    }

    /** Filtrar por el padre trae lo de sus hijas. */
    public function test_filtrar_por_la_categoria_padre_incluye_las_subcategorias(): void
    {
        $business = $this->storefrontBusiness();

        $padre = ProductCategory::factory()->create([
            'business_id' => $business->id,
            'is_published' => true,
            'parent_id' => null,
        ]);
        $hija = ProductCategory::factory()->create([
            'business_id' => $business->id,
            'is_published' => true,
            'parent_id' => $padre->id,
        ]);

        $this->publishedProduct($business, ['name' => 'De la hija', 'category_id' => $hija->id]);
        $this->publishedProduct($business, ['name' => 'De otra parte']);

        $response = $this->getJson(
            "/api/v1/storefront/{$business->slug}/products?category_id={$padre->id}"
        );

        $response->assertOk();
        $this->assertSame(['De la hija'], array_column($response->json('data'), 'name'));
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

    // -----------------------------------------------------------------
    // Filtros
    // -----------------------------------------------------------------

    public function test_el_rango_de_precio_usa_el_precio_publicado(): void
    {
        $business = $this->storefrontBusiness();

        // products.price altisimo, pero su variante mas barata vale 500: si
        // el filtro mirara la columna del producto, este quedaria fuera.
        $conVariantes = $this->publishedProduct($business, ['name' => 'Barato por variante', 'price' => 99000]);
        ProductVariant::factory()->create([
            'product_id' => $conVariantes->id,
            'business_id' => $business->id,
            'price' => 500,
            'is_active' => true,
        ]);

        $this->publishedProduct($business, ['name' => 'Caro', 'price' => 80000]);

        $response = $this->getJson("/api/v1/storefront/{$business->slug}/products?max_price=1000");

        $response->assertOk();
        $this->assertSame(['Barato por variante'], array_column($response->json('data'), 'name'));
    }

    public function test_solo_disponibles_deja_fuera_lo_agotado(): void
    {
        $business = $this->storefrontBusiness();

        $this->publishedProduct($business, ['name' => 'Con existencias', 'track_stock' => true, 'stock' => 5]);
        $this->publishedProduct($business, ['name' => 'Agotado', 'track_stock' => true, 'stock' => 0]);
        // Sin control de stock: siempre se puede pedir.
        $this->publishedProduct($business, ['name' => 'Sin control', 'track_stock' => false, 'stock' => 0]);

        $response = $this->getJson("/api/v1/storefront/{$business->slug}/products?in_stock=1");

        $response->assertOk();
        $nombres = array_column($response->json('data'), 'name');
        sort($nombres);
        $this->assertSame(['Con existencias', 'Sin control'], $nombres);
    }

    /** Un producto agotado en TODAS sus variantes tampoco pasa el filtro. */
    public function test_solo_disponibles_mira_las_variantes(): void
    {
        $business = $this->storefrontBusiness();

        $agotado = $this->publishedProduct($business, ['name' => 'Todas agotadas', 'track_stock' => true, 'stock' => 0]);
        ProductVariant::factory()->create([
            'product_id' => $agotado->id,
            'business_id' => $business->id,
            'stock' => 0,
            'is_active' => true,
        ]);

        $disponible = $this->publishedProduct($business, ['name' => 'Una con stock', 'track_stock' => true, 'stock' => 0]);
        ProductVariant::factory()->create([
            'product_id' => $disponible->id,
            'business_id' => $business->id,
            'stock' => 3,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/v1/storefront/{$business->slug}/products?in_stock=1");

        $response->assertOk();
        $this->assertSame(['Una con stock'], array_column($response->json('data'), 'name'));
    }
}
