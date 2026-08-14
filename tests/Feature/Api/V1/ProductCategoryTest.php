<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
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

    /**
     * Vender necesita filtrar por categoria sin pedirle a un cajero el
     * permiso de inventario (ver routes/api.php).
     */
    public function test_index_and_show_are_reachable_without_the_inventory_view_permission(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/product-categories')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/product-categories/{$category->id}")
            ->assertOk();
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

    public function test_cannot_delete_a_category_with_products(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        Product::factory()->create(['business_id' => $business->id, 'category_id' => $category->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/product-categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');

        $this->assertDatabaseHas('product_categories', ['id' => $category->id]);
    }

    public function test_cannot_delete_a_category_with_subcategories(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $parent = ProductCategory::factory()->create(['business_id' => $business->id]);
        ProductCategory::factory()->create(['business_id' => $business->id, 'parent_id' => $parent->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/product-categories/{$parent->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');

        $this->assertDatabaseHas('product_categories', ['id' => $parent->id]);
    }

    public function test_category_name_must_be_unique_within_the_business(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        ProductCategory::factory()->create(['business_id' => $business->id, 'name' => 'Bebidas']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/product-categories', ['name' => 'Bebidas'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_category_name_uniqueness_does_not_apply_across_businesses(): void
    {
        $otherBusiness = Business::factory()->create();
        ProductCategory::factory()->create(['business_id' => $otherBusiness->id, 'name' => 'Bebidas']);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/product-categories', ['name' => 'Bebidas'])
            ->assertCreated();
    }

    public function test_updating_a_category_can_keep_its_own_name(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $category = ProductCategory::factory()->create(['business_id' => $business->id, 'name' => 'Bebidas']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/product-categories/{$category->id}", ['name' => 'Bebidas'])
            ->assertOk();
    }

    public function test_parent_category_cannot_itself_be_a_subcategory(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $root = ProductCategory::factory()->create(['business_id' => $business->id]);
        $subcategory = ProductCategory::factory()->create(['business_id' => $business->id, 'parent_id' => $root->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/product-categories', [
                'name' => 'Tercer nivel',
                'parent_id' => $subcategory->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_category_cannot_be_its_own_parent(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/product-categories/{$category->id}", ['parent_id' => $category->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_a_category_with_children_cannot_become_a_subcategory(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $parent = ProductCategory::factory()->create(['business_id' => $business->id]);
        ProductCategory::factory()->create(['business_id' => $business->id, 'parent_id' => $parent->id]);
        $otherRoot = ProductCategory::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/product-categories/{$parent->id}", ['parent_id' => $otherRoot->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
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

    public function test_category_icon_is_returned_as_is_material_icon_name(): void
    {
        // El frontend renderiza el icono de categoria con Material Icons
        // (mismo vocabulario que el legacy, ver CategoryIconResolver) - la
        // API no traduce el valor, solo evita devolverlo vacio.
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $category = ProductCategory::factory()->create(['business_id' => $business->id, 'icon' => 'local_bar']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/product-categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('icon', 'local_bar');
    }
}
