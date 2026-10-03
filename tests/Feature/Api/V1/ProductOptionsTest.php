<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Sale;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Opciones de elección (salsas, toppings) por producto: catálogo, venta con
 * recargo e insumo, cuenta abierta, cocina y recibo.
 */
class ProductOptionsTest extends TestCase
{
    use DatabaseTransactions;

    private function adminAndBusiness(): array
    {
        $business = Business::factory()->create(['feature_flags' => array_merge(BusinessFeaturePresets::full(), ['product_options' => true])]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        return [$business, $user];
    }

    /** Alitas con un grupo "Salsa" (1 obligatoria): BBQ gratis con insumo, Picante +$1.000 sin insumo. */
    private function wingsWithSauces(Business $business, ?Ingredient $sauce = null, int $min = 1, int $max = 1): array
    {
        $wings = Product::factory()->create(['business_id' => $business->id, 'price' => 20000, 'track_stock' => false]);
        $group = ProductOptionGroup::create([
            'business_id' => $business->id,
            'name' => 'Salsa', 'min_choices' => $min, 'max_choices' => $max,
        ]);
        $wings->optionGroups()->attach($group->id);
        $bbq = ProductOption::create([
            'business_id' => $business->id, 'product_option_group_id' => $group->id,
            'name' => 'BBQ', 'extra_price' => 0,
            'ingredient_id' => $sauce?->id, 'ingredient_quantity' => $sauce ? 30 : null,
        ]);
        $hot = ProductOption::create([
            'business_id' => $business->id, 'product_option_group_id' => $group->id,
            'name' => 'Picante', 'extra_price' => 1000,
        ]);

        return [$wings, $bbq, $hot];
    }

    public function test_a_product_can_be_created_with_option_groups_and_returns_them(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Alitas x6',
            'category_id' => $category->id,
            'price' => 20000,
            'option_groups' => [[
                'name' => 'Salsa', 'min_choices' => 1, 'max_choices' => 2,
                'options' => [
                    ['name' => 'BBQ', 'extra_price' => 0],
                    ['name' => 'Picante', 'extra_price' => 1000],
                ],
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('option_groups.0.name', 'Salsa')
            ->assertJsonPath('option_groups.0.max_choices', 2)
            ->assertJsonPath('option_groups.0.options.1.extra_price', '1000.00');
        $this->assertDatabaseCount('product_options', 2);
    }

    public function test_updating_option_groups_edits_in_place_and_removes_missing_ones(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$wings, $bbq, $hot] = $this->wingsWithSauces($business);
        $groupId = $bbq->product_option_group_id;

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$wings->id}", [
            'option_groups' => [[
                'id' => $groupId, 'name' => 'Salsa', 'min_choices' => 0, 'max_choices' => 1,
                'options' => [['id' => $bbq->id, 'name' => 'BBQ ahumada', 'extra_price' => 500]],
            ]],
        ])->assertOk()->assertJsonCount(1, 'option_groups.0.options');

        $this->assertSame('BBQ ahumada', $bbq->fresh()->name);
        $this->assertDatabaseMissing('product_options', ['id' => $hot->id]);
    }

    public function test_max_choices_cannot_be_lower_than_min_choices(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Alitas', 'category_id' => $category->id, 'price' => 1000,
            'option_groups' => [[
                'name' => 'Salsa', 'min_choices' => 2, 'max_choices' => 1,
                'options' => [['name' => 'BBQ', 'extra_price' => 0], ['name' => 'Miel', 'extra_price' => 0]],
            ]],
        ])->assertUnprocessable();
    }

    public function test_selling_with_options_adds_the_surcharge_snapshots_the_choice_and_deducts_the_ingredient(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        $sauce = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 1000]);
        [$wings, $bbq] = $this->wingsWithSauces($business, $sauce);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $wings->id, 'quantity' => 2, 'options' => [$bbq->id]]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('items.0.options.0.name', 'BBQ')
            ->assertJsonPath('items.0.options.0.group', 'Salsa');
        $this->assertEquals(20000, $response->json('items.0.unit_price'));
        $this->assertSame('940.00', (string) $sauce->fresh()->stock);
        $this->assertDatabaseHas('sale_item_options', ['name' => 'BBQ', 'group_name' => 'Salsa']);
    }

    public function test_a_paid_surcharge_option_raises_the_line_price(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$wings, , $hot] = $this->wingsWithSauces($business);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $wings->id, 'quantity' => 2, 'options' => [$hot->id]]],
        ])->assertCreated();

        $this->assertEquals(21000, $response->json('items.0.unit_price'));
        $this->assertEquals(42000, $response->json('total'));
    }

    public function test_a_required_group_must_be_chosen(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$wings] = $this->wingsWithSauces($business);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $wings->id, 'quantity' => 1]],
        ])->assertUnprocessable();
    }

    public function test_cannot_choose_more_options_than_the_group_allows(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$wings, $bbq, $hot] = $this->wingsWithSauces($business);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $wings->id, 'quantity' => 1, 'options' => [$bbq->id, $hot->id]]],
        ])->assertUnprocessable();
    }

    public function test_cannot_use_an_option_that_belongs_to_another_product(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$wings] = $this->wingsWithSauces($business);
        [, $foreignBbq] = $this->wingsWithSauces($business);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $wings->id, 'quantity' => 1, 'options' => [$foreignBbq->id]]],
        ])->assertUnprocessable();
    }

    public function test_two_lines_of_the_same_product_with_different_options_are_not_merged(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$wings, $bbq, $hot] = $this->wingsWithSauces($business);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $wings->id, 'quantity' => 1, 'options' => [$bbq->id]]],
        ])->assertCreated()->json();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/open-tabs/{$tab['id']}/items", [
            'items' => [['product_id' => $wings->id, 'quantity' => 1, 'options' => [$hot->id]]],
        ])->assertOk()->assertJsonCount(2, 'items');
    }

    public function test_reversing_a_sale_restores_the_option_ingredient(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        $user->syncPermissions(['sales.reverse']);
        $sauce = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 1000]);
        [$wings, $bbq] = $this->wingsWithSauces($business, $sauce);

        $sale = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $wings->id, 'quantity' => 2, 'options' => [$bbq->id]]],
        ])->json();
        $this->assertSame('940.00', (string) $sauce->fresh()->stock);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/reverse")->assertNoContent();

        $this->assertSame('1000.00', (string) $sauce->fresh()->stock);
    }

    public function test_syncing_a_tab_re_deducts_the_option_ingredient_without_double_counting_and_cancelling_restores_it(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        $sauce = Ingredient::factory()->create(['business_id' => $business->id, 'stock' => 1000]);
        [$wings, $bbq] = $this->wingsWithSauces($business, $sauce);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $wings->id, 'quantity' => 1, 'options' => [$bbq->id]]],
        ])->json();
        $this->assertSame('970.00', (string) $sauce->fresh()->stock);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/open-tabs/{$tab['id']}/items", [
            'items' => [['product_id' => $wings->id, 'quantity' => 3, 'options' => [$bbq->id]]],
        ])->assertOk();
        $this->assertSame('910.00', (string) $sauce->fresh()->stock);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/open-tabs/{$tab['id']}")->assertSuccessful();
        $this->assertSame('1000.00', (string) $sauce->fresh()->stock);
    }

    public function test_the_kitchen_ticket_lists_the_chosen_options_and_the_receipt_shows_them(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$wings, , $hot] = $this->wingsWithSauces($business);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $wings->id, 'quantity' => 1, 'options' => [$hot->id]]],
        ])->json();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/kitchen/tickets')
            ->assertOk()
            ->assertJsonPath('0.items.0.options.0.name', 'Picante');

        $html = view('receipts.partials.body.sale', [
            'sale' => Sale::with('items.product', 'items.options', 'business')->find($tab['id']),
            'business' => $business,
            'invoiceNumber' => 'T-1',
            'issuedAt' => '01/01/2026 10:00',
            'paperWidthMm' => 80,
        ])->render();
        $this->assertStringContainsString('Picante', $html);
    }

    public function test_a_group_can_be_shared_by_several_products_and_edits_reach_all_of_them(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$wings6, $bbq] = $this->wingsWithSauces($business);
        $wings12 = Product::factory()->create(['business_id' => $business->id, 'price' => 38000, 'track_stock' => false]);
        $groupId = $bbq->product_option_group_id;

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$wings12->id}", [
            'option_groups' => [['id' => $groupId, 'name' => 'Salsa', 'min_choices' => 1, 'max_choices' => 1,
                'options' => [['id' => $bbq->id, 'name' => 'BBQ', 'extra_price' => 0]]]],
        ])->assertOk()->assertJsonPath('option_groups.0.id', $groupId);

        $this->assertSame(1, ProductOptionGroup::count());
        $this->assertSame(2, ProductOptionGroup::find($groupId)->products()->count());

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$wings12->id}", [
            'option_groups' => [['id' => $groupId, 'name' => 'Salsas de alitas', 'min_choices' => 1, 'max_choices' => 1,
                'options' => [['id' => $bbq->id, 'name' => 'BBQ', 'extra_price' => 0]]]],
        ])->assertOk();

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/products/{$wings6->id}")
            ->assertJsonPath('option_groups.0.name', 'Salsas de alitas');
    }

    public function test_unlinking_a_shared_group_keeps_it_for_the_other_product_but_deletes_it_when_unused(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$wings6, $bbq] = $this->wingsWithSauces($business);
        $wings12 = Product::factory()->create(['business_id' => $business->id, 'track_stock' => false]);
        $wings12->optionGroups()->attach($bbq->product_option_group_id);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$wings12->id}", ['option_groups' => []])->assertOk();
        $this->assertDatabaseHas('product_option_groups', ['id' => $bbq->product_option_group_id]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$wings6->id}", ['option_groups' => []])->assertOk();
        $this->assertDatabaseMissing('product_option_groups', ['id' => $bbq->product_option_group_id]);
    }

    public function test_a_group_of_another_business_is_never_linked(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [, $foreignBbq] = $this->wingsWithSauces(Business::factory()->create(['feature_flags' => array_merge(BusinessFeaturePresets::full(), ['product_options' => true])]));
        $product = Product::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$product->id}", [
            'option_groups' => [['id' => $foreignBbq->product_option_group_id, 'name' => 'Mia', 'min_choices' => 0, 'max_choices' => 1,
                'options' => [['name' => 'X', 'extra_price' => 0]]]],
        ])->assertOk();

        $this->assertSame(0, $product->optionGroups()->where('product_option_groups.id', $foreignBbq->product_option_group_id)->count());
        $this->assertSame('Salsa', ProductOptionGroup::withoutGlobalScopes()->find($foreignBbq->product_option_group_id)->name);
    }

    public function test_duplicating_a_product_links_the_same_option_groups(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$wings, $bbq] = $this->wingsWithSauces($business);

        $copyId = $this->actingAs($user, 'sanctum')->postJson("/api/v1/products/{$wings->id}/duplicate")
            ->assertSuccessful()
            ->assertJsonPath('option_groups.0.id', $bbq->product_option_group_id)
            ->json('id');

        $this->assertSame(1, ProductOptionGroup::count());
        $this->assertSame(2, ProductOptionGroup::find($bbq->product_option_group_id)->products()->count());
        $this->assertNotSame($wings->id, $copyId);
    }

    public function test_the_library_lists_groups_with_the_products_that_use_them(): void
    {
        [$business, $user] = $this->adminAndBusiness();
        [$wings] = $this->wingsWithSauces($business);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/product-option-groups')
            ->assertOk()
            ->assertJsonPath('0.name', 'Salsa')
            ->assertJsonPath('0.products.0.id', $wings->id)
            ->assertJsonCount(2, '0.options');
    }

    public function test_without_the_feature_option_groups_are_ignored_and_the_library_is_closed(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['product_options' => false]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Alitas', 'category_id' => $category->id, 'price' => 1000,
            'option_groups' => [['name' => 'Salsa', 'min_choices' => 0, 'max_choices' => 1, 'options' => [['name' => 'BBQ', 'extra_price' => 0]]]],
        ])->assertCreated();

        $this->assertSame(0, ProductOptionGroup::count());
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/product-option-groups')->assertForbidden();
    }

    public function test_the_feature_is_opt_in_even_for_businesses_without_flags(): void
    {
        $this->assertFalse(Business::factory()->create(['feature_flags' => null])->hasFeature('product_options'));
    }
}
