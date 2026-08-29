<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CRUD del catalogo de atributos combinables (Talla, Color, ...) reutilizable
 * por negocio - ver App\Http\Controllers\Api\V1\ProductAttributeController.
 */
class ProductAttributeTest extends TestCase
{
    use DatabaseTransactions;

    private function adminForNewBusiness(array $businessAttributes = []): array
    {
        $business = Business::factory()->create(['feature_flags' => ['variants' => true, ...($businessAttributes['feature_flags'] ?? [])]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        return [$business, $user];
    }

    public function test_creating_an_attribute_with_values(): void
    {
        [, $user] = $this->adminForNewBusiness();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/product-attributes', [
            'name' => 'Talla',
            'values' => [['value' => 'S'], ['value' => 'M'], ['value' => 'L']],
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Talla')
            ->assertJsonCount(3, 'values');
    }

    public function test_attribute_name_is_unique_per_business(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        ProductAttribute::factory()->create(['business_id' => $business->id, 'name' => 'Talla']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/product-attributes', [
            'name' => 'Talla',
            'values' => [['value' => 'S']],
        ])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_values_cannot_repeat_within_the_same_request(): void
    {
        [, $user] = $this->adminForNewBusiness();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/product-attributes', [
            'name' => 'Talla',
            'values' => [['value' => 'S'], ['value' => 'S']],
        ])->assertStatus(422);
    }

    public function test_updating_an_attribute_adds_and_removes_values(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id]);
        $keep = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'S']);
        ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'M']);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/product-attributes/{$attribute->id}", [
            'values' => [
                ['id' => $keep->id, 'value' => 'S'],
                ['value' => 'L'],
            ],
        ]);

        $response->assertOk()->assertJsonCount(2, 'values');
        $this->assertDatabaseHas('product_attribute_values', ['id' => $keep->id]);
        $this->assertDatabaseMissing('product_attribute_values', ['product_attribute_id' => $attribute->id, 'value' => 'M']);
        $this->assertDatabaseHas('product_attribute_values', ['product_attribute_id' => $attribute->id, 'value' => 'L']);
    }

    public function test_omitting_values_on_update_leaves_them_untouched(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id]);
        ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id, 'value' => 'S']);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/product-attributes/{$attribute->id}", [
            'name' => 'Talla renombrada',
        ])->assertOk();

        $this->assertDatabaseHas('product_attribute_values', ['product_attribute_id' => $attribute->id, 'value' => 'S']);
    }

    public function test_removing_a_value_already_used_by_a_variant_is_rejected(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id]);
        $value = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'business_id' => $business->id]);
        $variant->attributeValues()->attach($value->id, ['product_attribute_id' => $attribute->id]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/product-attributes/{$attribute->id}", [
            'values' => [['value' => 'Otro valor']],
        ])->assertStatus(422)->assertJsonValidationErrors('values');
    }

    public function test_deleting_an_attribute_in_use_by_a_variant_is_rejected(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id]);
        $value = ProductAttributeValue::factory()->create(['product_attribute_id' => $attribute->id, 'business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'business_id' => $business->id]);
        $variant->attributeValues()->attach($value->id, ['product_attribute_id' => $attribute->id]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/product-attributes/{$attribute->id}")
            ->assertStatus(422)->assertJsonValidationErrors('attribute');

        $this->assertDatabaseHas('product_attributes', ['id' => $attribute->id]);
    }

    public function test_deleting_an_unused_attribute_succeeds(): void
    {
        [$business, $user] = $this->adminForNewBusiness();
        $attribute = ProductAttribute::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/product-attributes/{$attribute->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('product_attributes', ['id' => $attribute->id]);
    }

    public function test_a_business_cannot_see_another_businesss_attributes(): void
    {
        [, $user] = $this->adminForNewBusiness();
        $otherAttribute = ProductAttribute::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/product-attributes')
            ->assertOk()->assertJsonMissing(['id' => $otherAttribute->id]);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/product-attributes/{$otherAttribute->id}")
            ->assertStatus(404);
    }

    public function test_the_endpoint_is_gated_behind_the_variants_feature(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['variants' => false]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/product-attributes')->assertStatus(403);
    }
}
