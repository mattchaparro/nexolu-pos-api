<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Catalogo publico de la tienda online: el primer endpoint de esta API al que
 * llega alguien sin sesion. Lo que mas se prueba aca no es que devuelva
 * productos, sino que NO devuelva de mas: ni datos de otro negocio, ni
 * costos, ni inventario exacto, ni productos sin publicar.
 */
class StorefrontCatalogTest extends TestCase
{
    use DatabaseTransactions;

    private function storefront(array $settings = []): Business
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
        ]);

        BusinessStoreSettings::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
            ...$settings,
        ]);

        return $business;
    }

    private function publishedProduct(Business $business, array $attributes = []): Product
    {
        $category = ProductCategory::factory()->create([
            'business_id' => $business->id,
            'is_published' => true,
        ]);

        return Product::factory()->create([
            'business_id' => $business->id,
            'category_id' => $category->id,
            'is_published' => true,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Aislamiento: la garantia principal
    // -----------------------------------------------------------------

    public function test_only_returns_products_of_the_business_in_the_url(): void
    {
        $mine = $this->storefront();
        $other = $this->storefront();

        $this->publishedProduct($mine, ['name' => 'Camiseta propia']);
        $this->publishedProduct($other, ['name' => 'Camiseta ajena']);

        $this->getJson("/api/v1/storefront/{$mine->slug}/products")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Camiseta propia');
    }

    public function test_a_product_of_another_business_is_not_reachable_by_id(): void
    {
        $mine = $this->storefront();
        $other = $this->storefront();
        $foreign = $this->publishedProduct($other);

        $this->getJson("/api/v1/storefront/{$mine->slug}/products/{$foreign->id}")
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Que no se filtre nada interno
    // -----------------------------------------------------------------

    public function test_never_exposes_cost_or_internal_fields(): void
    {
        $business = $this->storefront();
        $this->publishedProduct($business, ['cost_price' => 12345, 'sku' => 'SECRETO-1']);

        $response = $this->getJson("/api/v1/storefront/{$business->slug}/products")->assertOk();

        $body = $response->getContent();
        foreach (['cost_price', '12345', 'SECRETO-1', 'is_single_sale', 'can_manage_stock', 'track_stock'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "El catalogo publico filtro: {$forbidden}");
        }
    }

    public function test_the_exact_stock_is_capped(): void
    {
        $business = $this->storefront();
        $this->publishedProduct($business, ['stock' => 4823, 'track_stock' => true]);

        $this->getJson("/api/v1/storefront/{$business->slug}/products")
            ->assertOk()
            ->assertJsonPath('data.0.available.in_stock', true)
            ->assertJsonPath('data.0.available.quantity', 10);
    }

    // -----------------------------------------------------------------
    // Publicacion
    // -----------------------------------------------------------------

    public function test_unpublished_products_are_invisible(): void
    {
        $business = $this->storefront();
        $this->publishedProduct($business, ['name' => 'Publicado']);
        $this->publishedProduct($business, ['name' => 'Sin publicar', 'is_published' => false]);

        $this->getJson("/api/v1/storefront/{$business->slug}/products")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Publicado');
    }

    public function test_pausing_a_product_in_the_pos_removes_it_from_the_store(): void
    {
        $business = $this->storefront();
        $product = $this->publishedProduct($business);

        $this->getJson("/api/v1/storefront/{$business->slug}/products")->assertJsonCount(1, 'data');

        $product->update(['is_active' => false]);

        $this->getJson("/api/v1/storefront/{$business->slug}/products")->assertJsonCount(0, 'data');
    }

    public function test_services_are_not_listed(): void
    {
        $business = $this->storefront();
        $this->publishedProduct($business, ['is_service' => true]);

        $this->getJson("/api/v1/storefront/{$business->slug}/products")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_empty_categories_are_not_listed(): void
    {
        $business = $this->storefront();
        // Publicada pero sin productos visibles.
        ProductCategory::factory()->create(['business_id' => $business->id, 'is_published' => true]);
        $withProducts = $this->publishedProduct($business)->category;

        $this->getJson("/api/v1/storefront/{$business->slug}/categories")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $withProducts->id);
    }

    // -----------------------------------------------------------------
    // Visibilidad de la tienda
    // -----------------------------------------------------------------

    public function test_a_store_that_is_not_published_yet_returns_404(): void
    {
        // Modulo habilitado por SuperAdmin, pero el comerciante todavia no
        // abrio la tienda.
        $business = $this->storefront(['is_active' => false]);
        $this->publishedProduct($business);

        $this->getJson("/api/v1/storefront/{$business->slug}/products")->assertNotFound();
        $this->getJson("/api/v1/storefront/{$business->slug}")->assertNotFound();
    }

    public function test_a_business_that_never_opened_the_module_returns_404(): void
    {
        $business = Business::factory()->create([
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
        ]);

        $this->getJson("/api/v1/storefront/{$business->slug}/products")->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Contenido
    // -----------------------------------------------------------------

    public function test_settings_expose_the_public_identity_only(): void
    {
        $business = $this->storefront(['store_name' => 'Mi Tiendita', 'whatsapp_number' => '3001234567']);

        $response = $this->getJson("/api/v1/storefront/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('name', 'Mi Tiendita')
            ->assertJsonPath('slug', $business->slug);

        foreach (['nit', 'feature_flags', 'subscription_plan', 'trial_ends_at'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
    }

    public function test_a_product_with_variants_lists_them_with_their_own_price(): void
    {
        $business = $this->storefront();
        $product = $this->publishedProduct($business, ['stock' => 0]);

        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id, 'name' => 'Talla']);
        foreach ([['S', 45000, 3], ['L', 52000, 0]] as [$value, $price, $stock]) {
            $attributeValue = ProductAttributeValue::factory()->create([
                'product_attribute_id' => $attribute->id,
                'business_id' => $business->id,
                'value' => $value,
            ]);
            $variant = ProductVariant::factory()->create([
                'product_id' => $product->id,
                'business_id' => $business->id,
                'price' => $price,
                'stock' => $stock,
            ]);
            $variant->attributeValues()->sync([$attributeValue->id => ['product_attribute_id' => $attribute->id]]);
        }

        $response = $this->getJson("/api/v1/storefront/{$business->slug}/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('has_variants', true)
            ->assertJsonCount(2, 'variants');

        // El precio del producto es el de la variante mas barata.
        $this->assertEqualsWithDelta(45000, (float) $response->json('price'), 0.01);

        $variants = collect($response->json('variants'))->keyBy('label');
        $this->assertTrue($variants['S']['available']['in_stock']);
        $this->assertFalse($variants['L']['available']['in_stock']);
    }

    public function test_a_paused_variant_is_hidden_from_the_store(): void
    {
        $business = $this->storefront();
        $product = $this->publishedProduct($business);
        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id]);
        $value = ProductAttributeValue::factory()->create([
            'product_attribute_id' => $attribute->id,
            'business_id' => $business->id,
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'business_id' => $business->id,
            'is_active' => false,
        ]);
        $variant->attributeValues()->sync([$value->id => ['product_attribute_id' => $attribute->id]]);

        $this->getJson("/api/v1/storefront/{$business->slug}/products/{$product->id}")
            ->assertOk()
            ->assertJsonCount(0, 'variants');
    }

    public function test_search_does_not_match_the_internal_sku(): void
    {
        $business = $this->storefront();
        $this->publishedProduct($business, ['name' => 'Camiseta', 'sku' => 'INTERNO-99']);

        $this->getJson("/api/v1/storefront/{$business->slug}/products?search=INTERNO")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/storefront/{$business->slug}/products?search=Camiseta")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_storefront_needs_no_authentication(): void
    {
        $business = $this->storefront();
        $this->publishedProduct($business);

        $this->assertGuest();
        $this->getJson("/api/v1/storefront/{$business->slug}/products")->assertOk();
    }

    public function test_an_authenticated_employee_of_another_business_still_sees_only_the_store(): void
    {
        $mine = $this->storefront();
        $other = $this->storefront();
        $this->publishedProduct($mine, ['name' => 'De la tienda']);
        $this->publishedProduct($other, ['name' => 'Del otro negocio']);

        $employee = User::factory()->create(['business_id' => $other->id]);

        // Un empleado logueado navegando la tienda publica de OTRO negocio no
        // debe ver su propio catalogo mezclado.
        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/v1/storefront/{$mine->slug}/products")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'De la tienda');
    }
}
