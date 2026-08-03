<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
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

        Product::factory()->count(2)->create(['business_id' => $business->id]);
        Product::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_creating_a_product_without_an_explicit_sku_generates_one(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
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

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}")
            ->assertNotFound();
    }

    public function test_sku_must_be_unique_within_the_same_business(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
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
        $product = Product::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }
}
