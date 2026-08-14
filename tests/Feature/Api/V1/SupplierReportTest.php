<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierReportTest extends TestCase
{
    use DatabaseTransactions;

    private function adminWithPurchases(?Business $business = null): array
    {
        $business ??= Business::factory()->create([
            'feature_flags' => ['inventory' => true],
        ]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return [$business, $admin];
    }

    // ─── gate ─────────────────────────────────────────────────────────────────

    public function test_supplier_report_requires_purchases_feature(): void
    {
        $business = Business::factory()->create([
            'feature_flags' => ['inventory' => false, 'inventory_advanced' => false, 'ingredients' => false],
        ]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/suppliers')
            ->assertForbidden();
    }

    public function test_supplier_report_requires_purchases_manage_permission(): void
    {
        $business = Business::factory()->create([
            'feature_flags' => ['inventory' => true],
        ]);
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/suppliers')
            ->assertForbidden();
    }

    // ─── index ────────────────────────────────────────────────────────────────

    public function test_supplier_report_returns_lines_with_totals(): void
    {
        [$business, $admin] = $this->adminWithPurchases();

        $supplier = Supplier::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);
        $purchase = Purchase::factory()->create([
            'business_id' => $business->id,
            'supplier_id' => $supplier->id,
            'purchased_at' => '2026-03-10',
        ]);
        PurchaseLine::factory()->create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_cost_cop' => 2000,
            'line_total_cop' => 10000,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/suppliers');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('totals.total_cop', 10000)
            ->assertJsonPath('totals.total_qty', 5)
            ->assertJsonStructure(['data', 'meta', 'totals']);
    }

    public function test_supplier_report_filters_by_supplier(): void
    {
        [$business, $admin] = $this->adminWithPurchases();

        $supplierA = Supplier::factory()->create(['business_id' => $business->id]);
        $supplierB = Supplier::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);

        $purchaseA = Purchase::factory()->create([
            'business_id' => $business->id,
            'supplier_id' => $supplierA->id,
            'purchased_at' => '2026-03-05',
        ]);
        PurchaseLine::factory()->create([
            'purchase_id' => $purchaseA->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_cost_cop' => 1000,
            'line_total_cop' => 3000,
        ]);

        $purchaseB = Purchase::factory()->create([
            'business_id' => $business->id,
            'supplier_id' => $supplierB->id,
            'purchased_at' => '2026-03-07',
        ]);
        PurchaseLine::factory()->create([
            'purchase_id' => $purchaseB->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_cost_cop' => 500,
            'line_total_cop' => 5000,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/suppliers?supplier_id={$supplierA->id}");

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('totals.total_cop', 3000);
    }

    public function test_supplier_report_filters_by_date_range(): void
    {
        [$business, $admin] = $this->adminWithPurchases();

        $supplier = Supplier::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);

        $purchase = Purchase::factory()->create([
            'business_id' => $business->id,
            'supplier_id' => $supplier->id,
            'purchased_at' => '2026-03-10',
        ]);
        PurchaseLine::factory()->create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_cost_cop' => 3000,
            'line_total_cop' => 6000,
        ]);

        // compra fuera del rango
        $oldPurchase = Purchase::factory()->create([
            'business_id' => $business->id,
            'supplier_id' => $supplier->id,
            'purchased_at' => '2026-01-10',
        ]);
        PurchaseLine::factory()->create([
            'purchase_id' => $oldPurchase->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_cost_cop' => 1000,
            'line_total_cop' => 10000,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/suppliers?from=2026-03-01&to=2026-03-31');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('totals.total_cop', 6000);
    }

    public function test_supplier_report_export_returns_csv(): void
    {
        [, $admin] = $this->adminWithPurchases();

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/reports/suppliers/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    // ─── tenant isolation ─────────────────────────────────────────────────────

    public function test_report_does_not_leak_across_businesses(): void
    {
        [$business, $admin] = $this->adminWithPurchases();
        $otherBusiness = Business::factory()->create([
            'feature_flags' => ['inventory' => true],
        ]);

        $supplier = Supplier::factory()->create(['business_id' => $otherBusiness->id]);
        $product = Product::factory()->create(['business_id' => $otherBusiness->id]);
        $purchase = Purchase::factory()->create([
            'business_id' => $otherBusiness->id,
            'supplier_id' => $supplier->id,
            'purchased_at' => '2026-03-10',
        ]);
        PurchaseLine::factory()->create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 100,
            'unit_cost_cop' => 999,
            'line_total_cop' => 99900,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/suppliers');

        $response->assertOk()->assertJsonPath('meta.total', 0);
    }
}
