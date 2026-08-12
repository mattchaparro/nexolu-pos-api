<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IngredientTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_admin_can_create_an_ingredient(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ingredients', [
            'name' => 'Queso mozzarella',
            'unit' => 'kg',
            'stock' => 10,
            'min_stock' => 2,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Queso mozzarella')
            ->assertJsonPath('unit', 'kg')
            ->assertJsonPath('cost_price', '0.0000');

        $this->assertDatabaseHas('ingredients', ['business_id' => $admin->business_id, 'name' => 'Queso mozzarella']);
    }

    public function test_store_validates_required_fields(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ingredients', [])->assertStatus(422);
    }

    public function test_index_lists_only_the_business_ingredients(): void
    {
        $admin = $this->admin();
        $mine = Ingredient::factory()->create(['business_id' => $admin->business_id]);
        Ingredient::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/ingredients');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    public function test_index_and_show_expose_the_products_that_use_the_ingredient(): void
    {
        $admin = $this->admin();
        $bread = Ingredient::factory()->create(['business_id' => $admin->business_id]);
        $burger = Product::factory()->create(['business_id' => $admin->business_id, 'name' => 'Hamburguesa']);
        $burger->ingredients()->attach($bread->id, ['quantity' => 2]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/ingredients')
            ->assertOk()
            ->assertJsonPath('data.0.products.0.name', 'Hamburguesa');

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/ingredients/{$bread->id}")
            ->assertOk()
            ->assertJsonPath('products.0.name', 'Hamburguesa');
    }

    public function test_update_changes_fields(): void
    {
        $admin = $this->admin();
        $ingredient = Ingredient::factory()->create(['business_id' => $admin->business_id, 'name' => 'Vieja']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/ingredients/{$ingredient->id}", ['name' => 'Nueva'])
            ->assertOk()
            ->assertJsonPath('name', 'Nueva');
    }

    public function test_updating_the_cost_price_propagates_to_linked_product_recipe_cost(): void
    {
        $admin = $this->admin();
        $ingredient = Ingredient::factory()->create(['business_id' => $admin->business_id, 'cost_price' => 1000]);
        $product = Product::factory()->create(['business_id' => $admin->business_id]);
        $product->ingredients()->attach($ingredient->id, ['quantity' => 2]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/ingredients/{$ingredient->id}", ['cost_price' => 3000])
            ->assertOk();

        $this->assertSame('6000.00', (string) $product->fresh()->cost_price);
    }

    public function test_destroy_removes_the_ingredient(): void
    {
        $admin = $this->admin();
        $ingredient = Ingredient::factory()->create(['business_id' => $admin->business_id]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/ingredients/{$ingredient->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('ingredients', ['id' => $ingredient->id]);
    }

    public function test_a_business_without_the_ingredients_feature_is_rejected(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['ingredients' => false]]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ingredients', ['name' => 'x', 'unit' => 'kg'])
            ->assertStatus(403);
    }

    public function test_an_employee_without_inventory_permission_is_rejected(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/v1/ingredients', ['name' => 'x', 'unit' => 'kg'])
            ->assertStatus(403);
    }
}
