<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InventoryReportTest extends TestCase
{
    use DatabaseTransactions;

    private function adminWithInventory(?Business $business = null): array
    {
        $business ??= Business::factory()->create([
            'feature_flags' => ['inventory_advanced' => true],
        ]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return [$business, $admin];
    }

    // ─── gate ─────────────────────────────────────────────────────────────────

    public function test_summary_requires_inventory_advanced_or_ingredients_feature(): void
    {
        $business = Business::factory()->create([
            'feature_flags' => ['inventory' => true, 'inventory_advanced' => false, 'ingredients' => false],
        ]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/inventory/summary')
            ->assertForbidden();
    }

    public function test_summary_allowed_when_ingredients_feature_enabled(): void
    {
        $business = Business::factory()->create([
            'feature_flags' => ['inventory' => true, 'ingredients' => true, 'inventory_advanced' => false],
        ]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/inventory/summary')
            ->assertOk();
    }

    public function test_summary_requires_reports_inventory_permission(): void
    {
        $business = Business::factory()->create([
            'feature_flags' => ['inventory_advanced' => true],
        ]);
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/inventory/summary')
            ->assertForbidden();
    }

    // ─── summary ──────────────────────────────────────────────────────────────

    public function test_summary_returns_expected_keys(): void
    {
        [, $admin] = $this->adminWithInventory();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/inventory/summary');

        $response->assertOk()
            ->assertJsonStructure([
                'inventory_retail_cop',
                'inventory_cost_products_cop',
                'inventory_cost_ingredients_cop',
            ]);
    }

    // ─── movements ────────────────────────────────────────────────────────────

    public function test_movements_returns_paginated_movements(): void
    {
        [$business, $admin] = $this->adminWithInventory();

        $product = Product::factory()->create(['business_id' => $business->id]);

        StockMovement::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_ENTRY,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/inventory/movements');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_movements_filter_by_type(): void
    {
        [$business, $admin] = $this->adminWithInventory();

        $product = Product::factory()->create(['business_id' => $business->id]);

        StockMovement::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_ENTRY,
            'quantity' => 5,
        ]);
        StockMovement::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_EXIT,
            'quantity' => 3,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/inventory/movements?type='.StockMovement::TYPE_ENTRY);

        $response->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_movements_sort_by_quantity(): void
    {
        [$business, $admin] = $this->adminWithInventory();

        $product = Product::factory()->create(['business_id' => $business->id]);

        StockMovement::factory()->create([
            'business_id' => $business->id, 'product_id' => $product->id,
            'type' => StockMovement::TYPE_ENTRY, 'quantity' => 5,
        ]);
        StockMovement::factory()->create([
            'business_id' => $business->id, 'product_id' => $product->id,
            'type' => StockMovement::TYPE_ENTRY, 'quantity' => 20,
        ]);

        $ascending = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/inventory/movements?sort=quantity&direction=asc');
        $ascending->assertOk();
        $this->assertSame([5, 20], collect($ascending->json('data'))->pluck('quantity')->all());

        $descending = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/inventory/movements?sort=quantity&direction=desc');
        $descending->assertOk();
        $this->assertSame([20, 5], collect($descending->json('data'))->pluck('quantity')->all());
    }

    public function test_movements_ignores_unsupported_sort_and_falls_back_to_default(): void
    {
        [$business, $admin] = $this->adminWithInventory();

        $product = Product::factory()->create(['business_id' => $business->id]);
        StockMovement::factory()->create([
            'business_id' => $business->id, 'product_id' => $product->id,
            'type' => StockMovement::TYPE_ENTRY, 'quantity' => 1,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/inventory/movements?sort=not_a_real_column');

        $response->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_movements_export_returns_csv(): void
    {
        [, $admin] = $this->adminWithInventory();

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/reports/inventory/movements/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    // ─── margins ──────────────────────────────────────────────────────────────

    public function test_margins_returns_expected_structure(): void
    {
        [, $admin] = $this->adminWithInventory();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/inventory/margins');

        $response->assertOk()
            ->assertJsonStructure([
                'margin_rows', 'uncosted_rows',
                'categories', 'reasons', 'product_options', 'ingredient_options', 'filters',
            ]);
    }

    public function test_margins_includes_products_with_cost(): void
    {
        [$business, $admin] = $this->adminWithInventory();

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Café',
            'price' => 5000,
            'cost_price' => 2000,
            'stock' => 10,
            'track_stock' => true,
            'is_active' => true,
            'is_single_sale' => false,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/inventory/margins');

        $response->assertOk();
        $rows = $response->json('margin_rows');
        $found = collect($rows)->firstWhere('id', $product->id);

        $this->assertNotNull($found);
        $this->assertEquals(3000.0, $found['margin_cop']);
        $this->assertEquals(60.0, $found['margin_pct']);
        $this->assertEquals(30000.0, $found['profit_total']);
    }

    public function test_margins_export_returns_csv(): void
    {
        [, $admin] = $this->adminWithInventory();

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/reports/inventory/margins/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    // ─── tenant isolation ─────────────────────────────────────────────────────

    public function test_movements_do_not_leak_across_businesses(): void
    {
        [$business, $admin] = $this->adminWithInventory();
        $otherBusiness = Business::factory()->create([
            'feature_flags' => ['inventory_advanced' => true],
        ]);

        $product = Product::factory()->create(['business_id' => $otherBusiness->id]);

        StockMovement::factory()->create([
            'business_id' => $otherBusiness->id,
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_ENTRY,
            'quantity' => 99,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/inventory/movements');

        $response->assertOk()->assertJsonPath('meta.total', 0);
    }
}
