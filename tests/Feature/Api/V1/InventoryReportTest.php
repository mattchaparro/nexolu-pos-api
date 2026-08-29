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

    /**
     * Cubre InventoryReportService::summary() variant-aware (tarea de
     * Reportes): products.stock/price/cost_price quedan "fantasma" para un
     * producto con variantes, asi que la valorizacion debe sumar por
     * separado el stock*precio/costo de las variantes activas del negocio.
     */
    public function test_summary_includes_variant_stock_value_when_variants_feature_enabled(): void
    {
        $business = Business::factory()->create([
            'feature_flags' => ['inventory_advanced' => true, 'variants' => true],
        ]);
        [$business, $admin] = $this->adminWithInventory($business);

        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 0, 'price' => 0, 'cost_price' => 0]);
        $product->variants()->create(['business_id' => $business->id, 'sku' => 'VAR-1', 'price' => 1000, 'cost_price' => 600, 'stock' => 10]);
        $product->variants()->create(['business_id' => $business->id, 'sku' => 'VAR-2', 'price' => 1200, 'cost_price' => 700, 'stock' => 5, 'is_active' => false]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/reports/inventory/summary');

        // Solo la variante activa aporta: 10*1000 retail, 10*600 costo. La
        // inactiva (VAR-2) no debe sumar nada.
        $response->assertOk()->assertJson([
            'inventory_retail_cop' => 10000.0,
            'inventory_cost_products_cop' => 6000.0,
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
