<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Combos: producto armado con otros productos e insumos que descuenta cada pieza. */
class ProductComboTest extends TestCase
{
    use DatabaseTransactions;

    private function adminAndBusiness(bool $enabled = true): array
    {
        $business = Business::factory()->create(['feature_flags' => array_merge(BusinessFeaturePresets::full(), ['product_options' => $enabled])]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        return [$business, $user];
    }

    /** Combo = 1 Coca-Cola (producto con stock) + 100 de papas (insumo). */
    private function combo(Business $business, int $cokeStock = 10, float $potatoStock = 1000): array
    {
        $coke = Product::factory()->create(['business_id' => $business->id, 'price' => 4000, 'track_stock' => true, 'stock' => $cokeStock]);
        $potatoes = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => $potatoStock]);
        $combo = Product::factory()->create(['business_id' => $business->id, 'price' => 12000, 'track_stock' => false]);
        $combo->components()->createMany([
            ['business_id' => $business->id, 'component_product_id' => $coke->id, 'quantity' => 1, 'sort_order' => 0],
            ['business_id' => $business->id, 'ingredient_id' => $potatoes->id, 'quantity' => 100, 'sort_order' => 1],
        ]);

        return [$combo, $coke, $potatoes];
    }

    public function test_a_combo_can_be_created_with_components_and_loses_its_own_stock_tracking(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $coke = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 5]);
        $potatoes = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 500]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Combo Coca + papas',
            'category_id' => $category->id,
            'price' => 12000,
            'track_stock' => true,
            'components' => [
                ['component_product_id' => $coke->id, 'quantity' => 1],
                ['ingredient_id' => $potatoes->id, 'quantity' => 100],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('track_stock', false)
            ->assertJsonCount(2, 'components')
            ->assertJsonPath('components.1.quantity', 100);
    }

    public function test_a_component_must_be_a_product_or_an_ingredient_never_both_and_never_itself(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$combo, $coke, $potatoes] = $this->combo($business);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$combo->id}", [
            'components' => [['component_product_id' => $coke->id, 'ingredient_id' => $potatoes->id, 'quantity' => 1]],
        ])->assertUnprocessable();

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$combo->id}", [
            'components' => [['component_product_id' => $combo->id, 'quantity' => 1]],
        ])->assertUnprocessable();

        $other = Product::factory()->create(['business_id' => $business->id]);
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$other->id}", [
            'components' => [['component_product_id' => $combo->id, 'quantity' => 1]],
        ])->assertUnprocessable();
    }

    public function test_without_the_feature_components_are_ignored(): void
    {
        [$business, $user] = $this->adminAndBusiness(false);
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);
        $coke = Product::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Combo', 'category_id' => $category->id, 'price' => 1000,
            'components' => [['component_product_id' => $coke->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertDatabaseCount('product_components', 0);
    }

    public function test_selling_a_combo_deducts_every_component_and_reversing_restores_them(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        $user->syncPermissions(['sales.reverse']);
        [$combo, $coke, $potatoes] = $this->combo($business);

        $sale = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $combo->id, 'quantity' => 2]],
        ])->assertCreated()->json();

        $this->assertEquals(24000, $sale['total']);
        $this->assertSame(8, (int) $coke->fresh()->stock);
        $this->assertSame('800.00', (string) $potatoes->fresh()->stock);
        $this->assertDatabaseHas('sale_item_components', ['name' => $coke->name]);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/reverse")->assertNoContent();

        $this->assertSame(10, (int) $coke->fresh()->stock);
        $this->assertSame('1000.00', (string) $potatoes->fresh()->stock);
    }

    public function test_a_combo_cannot_be_sold_when_one_component_runs_short(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$combo, $coke, $potatoes] = $this->combo($business, cokeStock: 10, potatoStock: 150);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $combo->id, 'quantity' => 2]],
        ])->assertUnprocessable();

        $this->assertSame(10, (int) $coke->fresh()->stock);
        $this->assertSame('150.00', (string) $potatoes->fresh()->stock);
    }

    public function test_the_catalog_reports_how_many_combos_can_be_made(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$combo] = $this->combo($business, cokeStock: 10, potatoStock: 350);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonFragment(['id' => $combo->id, 'combo_stock' => 3]);
    }

    public function test_syncing_a_tab_re_deducts_without_double_counting_and_cancelling_restores(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$combo, $coke, $potatoes] = $this->combo($business);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $combo->id, 'quantity' => 1]],
        ])->assertCreated()->json();
        $this->assertSame(9, (int) $coke->fresh()->stock);
        $this->assertSame('900.00', (string) $potatoes->fresh()->stock);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/open-tabs/{$tab['id']}/items", [
            'items' => [['product_id' => $combo->id, 'quantity' => 3]],
        ])->assertOk();
        $this->assertSame(7, (int) $coke->fresh()->stock);
        $this->assertSame('700.00', (string) $potatoes->fresh()->stock);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/open-tabs/{$tab['id']}")->assertSuccessful();
        $this->assertSame(10, (int) $coke->fresh()->stock);
        $this->assertSame('1000.00', (string) $potatoes->fresh()->stock);
    }
}
