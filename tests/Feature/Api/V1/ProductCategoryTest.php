<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductCategoryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_list_only_their_business_categories(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        ProductCategory::factory()->count(2)->create(['business_id' => $business->id]);
        ProductCategory::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/product-categories')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_user_can_create_a_category_scoped_to_their_business(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/product-categories', ['name' => 'Bebidas']);

        $response->assertCreated()->assertJsonPath('name', 'Bebidas');
        $this->assertDatabaseHas('product_categories', [
            'name' => 'Bebidas',
            'business_id' => $business->id,
        ]);
    }

    public function test_user_cannot_view_a_category_from_another_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $category = ProductCategory::factory()->create(['business_id' => $otherBusiness->id]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/product-categories/{$category->id}")
            ->assertNotFound();
    }

    public function test_user_cannot_assign_a_parent_category_from_another_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreignParent = ProductCategory::factory()->create(['business_id' => $otherBusiness->id]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/product-categories', [
                'name' => 'Subcategoria',
                'parent_id' => $foreignParent->id,
            ])
            ->assertStatus(422);
    }

    public function test_user_can_delete_a_category(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/product-categories/{$category->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('product_categories', ['id' => $category->id]);
    }

    public function test_created_category_response_reflects_the_database_default_icon(): void
    {
        // Bug real: store() no refrescaba de BD, asi que "icon" (DEFAULT
        // 'inventory_2') llegaba null al cliente si el request no lo mandaba.
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/product-categories', ['name' => 'Sin icono explicito'])
            ->assertCreated()
            ->assertJsonPath('icon', 'inventory_2');
    }
}
