<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cubre el CRUD de productos con variantes (product_variants +
 * product_variant_attribute_value), gestionado como parte del payload de
 * POST/PUT /v1/products - mismo patron que ProductRecipeTest para
 * ingredients. Ver ProductService::extractVariants()/syncVariants() y las
 * reglas en App\Http\Requests\Concerns\ValidatesProductVariants.
 */
class ProductVariantTest extends TestCase
{
    use DatabaseTransactions;

    private function adminForNewBusiness(array $businessAttributes = []): array
    {
        $business = Business::factory()->create([
            'feature_flags' => ['variants' => true, ...($businessAttributes['feature_flags'] ?? [])],
        ]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        return [$business, $user];
    }

    /** @return array{0: Business, 1: User, 2: ProductAttributeValue, 3: ProductAttributeValue} */
    private function businessWithSizeAttribute(): array
    {
        [$business, $user] = $this->adminForNewBusiness();
        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id, 'name' => 'Talla']);
        $small = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'S']);
        $medium = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'M']);

        return [$business, $user, $small, $medium];
    }

    public function test_creating_a_product_with_variants_creates_the_variant_rows_and_pivot(): void
    {
        [$business, $user, $small, $medium] = $this->businessWithSizeAttribute();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Camiseta',
            'price' => 50000,
            'category_id' => $category->id,
            'variants' => [
                ['sku' => 'CAM-S', 'price' => 45000, 'stock' => 10, 'attribute_value_ids' => [$small->id]],
                ['sku' => 'CAM-M', 'price' => 47000, 'stock' => 5, 'attribute_value_ids' => [$medium->id]],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('has_variants', true)
            ->assertJsonPath('track_stock', true)
            ->assertJsonCount(2, 'variants');

        $product = Product::where('name', 'Camiseta')->first();
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'CAM-S', 'stock' => 10]);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'CAM-M', 'stock' => 5]);
        $this->assertSame(0, $product->stock, 'products.stock queda fantasma para un producto con variantes');
    }

    public function test_variants_without_sku_get_one_derived_from_the_parent_product(): void
    {
        // Product ya autogeneraba su sku; la variante no, y ademas lo exigia,
        // asi que un producto con talla x color obligaba a inventar un codigo
        // por combinacion antes de poder guardar.
        [$business, $user, $small, $medium] = $this->businessWithSizeAttribute();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Camiseta sin skus',
            'price' => 50000,
            'category_id' => $category->id,
            'variants' => [
                ['price' => 45000, 'stock' => 10, 'attribute_value_ids' => [$small->id]],
                ['price' => 47000, 'stock' => 5, 'attribute_value_ids' => [$medium->id]],
            ],
        ])->assertCreated();

        $product = Product::where('name', 'Camiseta sin skus')->firstOrFail();
        $skus = ProductVariant::where('product_id', $product->id)->orderBy('id')->pluck('sku')->all();

        $this->assertSame([$product->sku.'-1', $product->sku.'-2'], $skus);
        $this->assertCount(2, array_unique($skus));
        $this->assertCount(2, $response->json('variants'));
    }

    public function test_an_explicit_sku_still_wins_over_the_generated_one(): void
    {
        [$business, $user, $small, $medium] = $this->businessWithSizeAttribute();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Camiseta mixta',
            'price' => 50000,
            'category_id' => $category->id,
            'variants' => [
                ['sku' => 'MIA-S', 'price' => 45000, 'attribute_value_ids' => [$small->id]],
                ['price' => 47000, 'attribute_value_ids' => [$medium->id]],
            ],
        ])->assertCreated();

        $product = Product::where('name', 'Camiseta mixta')->firstOrFail();
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'MIA-S']);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => $product->sku.'-1']);
    }

    public function test_rejects_duplicate_attribute_combinations(): void
    {
        [$business, $user, $small] = $this->businessWithSizeAttribute();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Camiseta',
            'price' => 50000,
            'category_id' => $category->id,
            'variants' => [
                ['sku' => 'CAM-S1', 'price' => 45000, 'attribute_value_ids' => [$small->id]],
                ['sku' => 'CAM-S2', 'price' => 46000, 'attribute_value_ids' => [$small->id]],
            ],
        ])->assertStatus(422);
    }

    public function test_creating_a_service_with_variants_is_rejected(): void
    {
        [$business, $user, $small] = $this->businessWithSizeAttribute();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Corte de cabello',
            'price' => 20000,
            'category_id' => $category->id,
            'is_service' => true,
            'variants' => [['sku' => 'X', 'price' => 1000, 'attribute_value_ids' => [$small->id]]],
        ])->assertStatus(422)->assertJsonValidationErrors('variants');
    }

    public function test_creating_a_single_sale_product_with_variants_is_rejected(): void
    {
        [$business, $user, $small] = $this->businessWithSizeAttribute();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Perfume unico',
            'price' => 90000,
            'category_id' => $category->id,
            'is_single_sale' => true,
            'variants' => [['sku' => 'X', 'price' => 1000, 'attribute_value_ids' => [$small->id]]],
        ])->assertStatus(422)->assertJsonValidationErrors('variants');
    }

    public function test_creating_a_product_with_both_variants_and_ingredients_is_rejected(): void
    {
        [$business, $user, $small] = $this->businessWithSizeAttribute();
        $business->update(['feature_flags' => ['variants' => true, 'ingredients' => true]]);
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $ingredient = Ingredient::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Mixto',
            'price' => 1000,
            'category_id' => $category->id,
            'ingredients' => [['ingredient_id' => $ingredient->id, 'quantity' => 1]],
            'variants' => [['sku' => 'X', 'price' => 1000, 'attribute_value_ids' => [$small->id]]],
        ])->assertStatus(422)->assertJsonValidationErrors('variants');
    }

    public function test_a_variant_sku_from_another_business_is_still_allowed(): void
    {
        [$business, $user, $small] = $this->businessWithSizeAttribute();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $otherProduct = Product::factory()->create();
        $otherProduct->variants()->create(['business_id' => $otherProduct->business_id, 'sku' => 'SHARED-SKU', 'price' => 1, 'stock' => 0]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Camiseta',
            'price' => 50000,
            'category_id' => $category->id,
            'variants' => [['sku' => 'SHARED-SKU', 'price' => 1000, 'attribute_value_ids' => [$small->id]]],
        ])->assertCreated();
    }

    public function test_a_duplicate_sku_within_the_same_business_is_rejected(): void
    {
        [$business, $user, $small, $medium] = $this->businessWithSizeAttribute();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $existingProduct = Product::factory()->create(['business_id' => $business->id]);
        $existingProduct->variants()->create(['business_id' => $business->id, 'sku' => 'DUP-SKU', 'price' => 1, 'stock' => 0]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Camiseta',
            'price' => 50000,
            'category_id' => $category->id,
            'variants' => [['sku' => 'DUP-SKU', 'price' => 1000, 'attribute_value_ids' => [$small->id]]],
        ])->assertStatus(422)->assertJsonValidationErrors('variants.0.sku');
    }

    public function test_updating_a_products_variants_adds_edits_and_removes_rows(): void
    {
        [$business, $user, $small, $medium] = $this->businessWithSizeAttribute();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'category_id' => $category->id]);
        $keep = $product->variants()->create(['business_id' => $business->id, 'sku' => 'KEEP', 'price' => 1000, 'stock' => 1]);
        $keep->attributeValues()->attach($small->id, ['product_attribute_id' => $small->product_attribute_id]);
        $drop = $product->variants()->create(['business_id' => $business->id, 'sku' => 'DROP', 'price' => 1000, 'stock' => 1]);
        $drop->attributeValues()->attach($medium->id, ['product_attribute_id' => $medium->product_attribute_id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$product->id}", [
            'variants' => [
                ['id' => $keep->id, 'sku' => 'KEEP', 'price' => 2000, 'attribute_value_ids' => [$small->id]],
            ],
        ]);

        $response->assertOk()->assertJsonCount(1, 'variants')->assertJsonPath('variants.0.price', '2000.00');
        $this->assertDatabaseHas('product_variants', ['id' => $keep->id, 'price' => 2000]);
        $this->assertSoftDeleted('product_variants', ['id' => $drop->id]);
    }

    public function test_omitting_variants_on_update_leaves_them_untouched(): void
    {
        [$business, $user, $small] = $this->businessWithSizeAttribute();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['business_id' => $business->id, 'sku' => 'KEEP', 'price' => 1000, 'stock' => 1]);
        $variant->attributeValues()->attach($small->id, ['product_attribute_id' => $small->product_attribute_id]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$product->id}", [
            'price' => 60000,
        ])->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id]);
    }

    public function test_sellable_and_index_expose_has_variants_and_variant_list(): void
    {
        [$business, $user, $small] = $this->businessWithSizeAttribute();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'category_id' => $category->id, 'is_active' => true]);
        $variant = $product->variants()->create(['business_id' => $business->id, 'sku' => 'V1', 'price' => 1000, 'stock' => 9]);
        $variant->attributeValues()->attach($small->id, ['product_attribute_id' => $small->product_attribute_id]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/products/sellable')
            ->assertOk()
            ->assertJsonFragment(['id' => $product->id, 'has_variants' => true, 'stock' => 9]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/products?is_service=0')
            ->assertOk()
            ->assertJsonFragment(['has_variants' => true]);
    }

    public function test_a_product_without_variants_behaves_exactly_like_before(): void
    {
        [$business, $user] = $this->businessWithSizeAttribute();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'category_id' => $category->id, 'stock' => 20]);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('has_variants', false)
            ->assertJsonPath('stock', 20)
            ->assertJsonPath('can_manage_stock', true)
            ->assertJsonCount(0, 'variants');
    }

    public function test_variants_are_ignored_when_the_business_has_the_feature_disabled(): void
    {
        [$business, $user, $small] = $this->businessWithSizeAttribute();
        $business->update(['feature_flags' => ['variants' => false]]);
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Producto',
            'price' => 1000,
            'category_id' => $category->id,
            'variants' => [['sku' => 'X', 'price' => 1000, 'attribute_value_ids' => [$small->id]]],
        ]);

        $response->assertCreated();
        $product = Product::where('name', 'Producto')->first();
        $this->assertDatabaseMissing('product_variants', ['product_id' => $product->id]);
    }
}
