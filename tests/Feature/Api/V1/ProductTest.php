<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_list_only_their_business_products(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Product::factory()->count(2)->create(['business_id' => $business->id]);
        Product::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_per_page_can_be_bumped_up_to_the_pos_catalog_cap(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Product::factory()->count(30)->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products?per_page=200')
            ->assertOk()
            ->assertJsonCount(30, 'data');
    }

    public function test_per_page_above_the_cap_is_clamped_to_200(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Product::factory()->count(3)->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products?per_page=9999')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 200);
    }

    /**
     * A diferencia de index() (topeado en 200), sellable() es el catalogo
     * completo que usa Vender - con mas de 200 productos, index() nunca le
     * mandaria al navegador los que caen despues en el orden alfabetico
     * (ej. los que empiezan por "Z").
     */
    public function test_sellable_returns_the_full_catalog_beyond_the_pagination_cap_of_index(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['ingredients' => false]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Product::factory()->count(210)->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products/sellable')
            ->assertOk()
            ->assertJsonCount(210);
    }

    public function test_sellable_excludes_inactive_products(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['ingredients' => false]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $active = Product::factory()->create(['business_id' => $business->id, 'is_active' => true]);
        Product::factory()->create(['business_id' => $business->id, 'is_active' => false]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products/sellable')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $active->id);
    }

    public function test_products_can_be_searched_by_name_or_sku(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Product::factory()->create(['business_id' => $business->id, 'name' => 'Gaseosa Manzana', 'sku' => 'BEB-001']);
        Product::factory()->create(['business_id' => $business->id, 'name' => 'Papas fritas', 'sku' => 'SNK-002']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products?search=gaseosa')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Gaseosa Manzana');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products?search=SNK-002')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Papas fritas');
    }

    public function test_products_can_be_filtered_by_category_including_subcategories(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $drinks = ProductCategory::factory()->create(['business_id' => $business->id]);
        $sodas = ProductCategory::factory()->create(['business_id' => $business->id, 'parent_id' => $drinks->id]);
        $snacks = ProductCategory::factory()->create(['business_id' => $business->id]);

        Product::factory()->create(['business_id' => $business->id, 'category_id' => $drinks->id, 'name' => 'Agua']);
        Product::factory()->create(['business_id' => $business->id, 'category_id' => $sodas->id, 'name' => 'Gaseosa']);
        Product::factory()->create(['business_id' => $business->id, 'category_id' => $snacks->id, 'name' => 'Papas']);

        // Filtrar por la categoria raiz "Bebidas" trae tambien la
        // subcategoria "Gaseosas" - igual que Admin\InventoryController del
        // legacy (ProductCategory::idsIncludingChildren()).
        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/products?category_id={$drinks->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Agua'));
        $this->assertTrue($names->contains('Gaseosa'));
        $this->assertFalse($names->contains('Papas'));
    }

    public function test_products_can_be_filtered_by_stock_state(): void
    {
        $business = Business::factory()->create(['low_stock_alert_threshold' => 5]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        // stock explicito en todos (no solo en los casos que le importan a
        // cada aserto) - el factory por defecto lo randomiza entre 0-100, lo
        // que haria flakys los assertJsonCount(1) de out_of_stock/low_stock
        // si otro producto cae ahi por azar.
        $outOfStock = Product::factory()->create(['business_id' => $business->id, 'stock' => 0]);
        $lowStock = Product::factory()->create(['business_id' => $business->id, 'stock' => 3, 'low_stock_alert_threshold' => null]);
        $wellStocked = Product::factory()->create(['business_id' => $business->id, 'stock' => 50, 'low_stock_alert_threshold' => null]);
        $inactive = Product::factory()->create(['business_id' => $business->id, 'stock' => 50, 'is_active' => false]);
        $singleSale = Product::factory()->create(['business_id' => $business->id, 'stock' => 50, 'is_single_sale' => true]);
        $withRecipe = Product::factory()->create(['business_id' => $business->id, 'stock' => 50]);
        $ingredient = Ingredient::factory()->create(['business_id' => $business->id]);
        $withRecipe->ingredients()->attach($ingredient->id, ['quantity' => 1]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products?filter=out_of_stock')
            ->assertOk()
            ->assertJsonPath('data.0.id', $outOfStock->id)
            ->assertJsonCount(1, 'data');

        // low_stock incluye tambien al que ya esta en 0 (stock <= umbral,
        // sin excluir 0) - igual que las cards de resumen de summary(), que
        // tampoco son mutuamente excluyentes entre si.
        $lowStockResponse = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products?filter=low_stock')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $lowStockIds = collect($lowStockResponse->json('data'))->pluck('id');
        $this->assertTrue($lowStockIds->contains($outOfStock->id));
        $this->assertTrue($lowStockIds->contains($lowStock->id));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products?filter=inactive')
            ->assertOk()
            ->assertJsonPath('data.0.id', $inactive->id)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products?filter=single_sale')
            ->assertOk()
            ->assertJsonPath('data.0.id', $singleSale->id)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products?filter=recipe')
            ->assertOk()
            ->assertJsonPath('data.0.id', $withRecipe->id)
            ->assertJsonCount(1, 'data');

        $this->assertNotNull($wellStocked);
    }

    public function test_is_service_filters_goods_and_services_apart(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Product::factory()->create(['business_id' => $business->id, 'is_service' => false]);
        Product::factory()->create(['business_id' => $business->id, 'is_service' => true, 'track_stock' => false]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products?is_service=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_service', false);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products?is_service=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_service', true);

        // Sin el parametro, Vender necesita ver ambos en la misma consulta.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_for_layaway_excludes_services_inactive_and_out_of_stock_products(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $eligible = Product::factory()->create(['business_id' => $business->id, 'stock' => 5, 'track_stock' => true]);
        $service = Product::factory()->create(['business_id' => $business->id, 'is_service' => true, 'track_stock' => false]);
        $inactive = Product::factory()->create(['business_id' => $business->id, 'is_active' => false]);
        $outOfStock = Product::factory()->create(['business_id' => $business->id, 'stock' => 0, 'track_stock' => true]);
        $untracked = Product::factory()->create(['business_id' => $business->id, 'track_stock' => false]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products?for_layaway=1')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($eligible->id));
        $this->assertTrue($ids->contains($untracked->id));
        $this->assertFalse($ids->contains($service->id));
        $this->assertFalse($ids->contains($inactive->id));
        $this->assertFalse($ids->contains($outOfStock->id));
    }

    public function test_for_layaway_respects_the_business_category_allowlist(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $allowedCategory = ProductCategory::factory()->create(['business_id' => $business->id]);
        $business->update(['layaway_allowed_category_ids' => [$allowedCategory->id]]);

        $allowed = Product::factory()->create(['business_id' => $business->id, 'category_id' => $allowedCategory->id]);
        $otherCategory = ProductCategory::factory()->create(['business_id' => $business->id]);
        $notAllowed = Product::factory()->create(['business_id' => $business->id, 'category_id' => $otherCategory->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products?for_layaway=1')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($allowed->id));
        $this->assertFalse($ids->contains($notAllowed->id));
    }

    public function test_for_layaway_include_ids_brings_back_out_of_stock_products_already_on_the_layaway(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $outOfStock = Product::factory()->create(['business_id' => $business->id, 'stock' => 0, 'track_stock' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products?for_layaway=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/products?for_layaway=1&include_ids[]={$outOfStock->id}")
            ->assertOk();

        $this->assertTrue(collect($response->json('data'))->pluck('id')->contains($outOfStock->id));
    }

    public function test_creating_a_service_forces_no_stock_tracking_regardless_of_what_the_client_sends(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Corte de cabello',
            'category_id' => $category->id,
            'price' => 25000,
            'is_service' => true,
            // Un cliente descuidado (o la API de IA) podria mandar esto -
            // ProductService debe normalizarlo, no confiar en el request.
            'track_stock' => true,
            'is_single_sale' => true,
            'low_stock_alert_threshold' => 5,
        ]);

        $response->assertCreated()
            ->assertJsonPath('track_stock', false)
            ->assertJsonPath('is_single_sale', false)
            ->assertJsonPath('low_stock_alert_threshold', null);
    }

    public function test_creating_a_product_without_an_explicit_sku_generates_one(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Cafe Americano',
            'price' => 5000,
            'category_id' => $category->id,
        ]);

        $response->assertCreated()->assertJsonPath('sku', 'PROD-001');

        $second = $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Cafe Latte',
            'price' => 6000,
            'category_id' => $category->id,
        ]);

        $second->assertCreated()->assertJsonPath('sku', 'PROD-002');
    }

    public function test_product_is_scoped_to_the_authenticated_users_business(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Cafe Americano',
            'price' => 5000,
            'category_id' => $category->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('products', [
            'name' => 'Cafe Americano',
            'business_id' => $business->id,
        ]);
    }

    public function test_user_cannot_assign_a_category_from_another_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreignCategory = ProductCategory::factory()->create(['business_id' => $otherBusiness->id]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Producto',
                'price' => 1000,
                'category_id' => $foreignCategory->id,
            ])
            ->assertStatus(422);
    }

    public function test_user_cannot_view_a_product_from_another_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $otherBusiness->id]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}")
            ->assertNotFound();
    }

    public function test_sku_must_be_unique_within_the_same_business(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        Product::factory()->create(['business_id' => $business->id, 'sku' => 'PROD-999']);
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Duplicado',
                'price' => 1000,
                'category_id' => $category->id,
                'sku' => 'PROD-999',
            ])
            ->assertStatus(422);
    }

    public function test_user_can_update_a_product(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 1000]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/products/{$product->id}", ['price' => 2500])
            ->assertOk()
            ->assertJsonPath('price', '2500.00');

        $this->assertSame('2500.00', $product->fresh()->price);
    }

    public function test_user_can_delete_a_product(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_created_product_response_reflects_database_defaults_not_nulls(): void
    {
        // Bug real: store() devolvia el modelo recien creado sin refrescar de
        // BD, asi que columnas con DEFAULT que el request no manda (is_active,
        // track_stock, etc.) llegaban null al cliente aunque en la fila real
        // ya tenian su valor por defecto.
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Producto sin flags explicitos',
            'price' => 1000,
            'category_id' => $category->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('track_stock', true)
            ->assertJsonPath('is_single_sale', false)
            ->assertJsonPath('is_service', false)
            ->assertJsonPath('price_varies_at_sale', false);
    }
}
