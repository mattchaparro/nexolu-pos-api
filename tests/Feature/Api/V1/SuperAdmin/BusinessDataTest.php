<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class BusinessDataTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_superadmin_can_list_and_manage_a_businesss_products(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        Product::factory()->create(['business_id' => $business->id, 'category_id' => $category->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/superadmin/businesses/{$business->id}/products")
            ->assertOk()
            ->assertJsonCount(1);

        $store = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/superadmin/businesses/{$business->id}/products", [
            'name' => 'Producto SuperAdmin',
            'price' => 5000,
            'category_id' => $category->id,
        ]);
        $store->assertCreated();
        $this->assertDatabaseHas('products', ['name' => 'Producto SuperAdmin', 'business_id' => $business->id]);

        $productId = $store->json('id');
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/superadmin/businesses/{$business->id}/products/{$productId}", [
                'name' => 'Producto Editado',
                'price' => 6000,
                'category_id' => $category->id,
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Producto Editado');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/superadmin/businesses/{$business->id}/products/{$productId}")
            ->assertNoContent();
    }

    public function test_cannot_manage_a_product_belonging_to_a_different_business(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/superadmin/businesses/{$business->id}/products/{$product->id}")
            ->assertNotFound();
    }

    public function test_superadmin_can_manage_a_businesss_categories(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();

        $store = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/superadmin/businesses/{$business->id}/categories", [
            'name' => 'Bebidas',
            'icon' => 'local_bar',
        ]);
        $store->assertCreated();

        $categoryId = $store->json('id');
        Product::factory()->create(['business_id' => $business->id, 'category_id' => $categoryId]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/superadmin/businesses/{$business->id}/categories/{$categoryId}")
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/superadmin/businesses/{$business->id}/categories")
            ->assertOk()
            ->assertJsonPath('0.id', $categoryId);
    }
}
