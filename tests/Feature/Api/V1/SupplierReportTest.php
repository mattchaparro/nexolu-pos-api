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

    public function test_supplier_report_groups_by_month_and_by_supplier(): void
    {
        [$business, $admin] = $this->adminWithPurchases();
        $acme = Supplier::factory()->create(['business_id' => $business->id, 'name' => 'Acme']);
        $beta = Supplier::factory()->create(['business_id' => $business->id, 'name' => 'Beta']);
        $product = Product::factory()->create(['business_id' => $business->id]);

        $buy = function (?Supplier $supplier, string $date, float $total) use ($business, $product): void {
            $purchase = Purchase::factory()->create([
                'business_id' => $business->id,
                'supplier_id' => $supplier?->id,
                'purchased_at' => $date,
            ]);
            PurchaseLine::factory()->create([
                'purchase_id' => $purchase->id, 'product_id' => $product->id,
                'quantity' => 1, 'unit_cost_cop' => $total, 'line_total_cop' => $total,
            ]);
        };
        $buy($acme, '2026-09-03', 100000);
        $buy($acme, '2026-10-15', 50000);
        $buy($beta, '2026-10-20', 30000);
        $buy(null, '2026-10-22', 20000);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/suppliers?from=2026-09-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('totals.total_cop', 200000)
            ->assertJsonPath('totals.purchase_count', 4)
            ->assertJsonPath('totals.supplier_count', 2)
            ->assertJsonPath('totals.month_count', 2)
            ->assertJsonPath('by_month.0.month', '2026-09')
            ->assertJsonPath('by_month.0.total_cop', 100000)
            ->assertJsonPath('by_month.1.month', '2026-10')
            ->assertJsonPath('by_month.1.total_cop', 100000)
            ->assertJsonPath('by_month.1.purchase_count', 3);

        $suppliers = collect($response->json('by_supplier'))->keyBy('supplier_name');
        $this->assertSame(['Acme', 'Beta', 'Sin proveedor'], array_keys($suppliers->all()));
        $this->assertEquals(150000, $suppliers['Acme']['total_cop']);
        $this->assertEquals(75, $suppliers['Acme']['pct_of_total']);
        $this->assertSame('2026-10-15', $suppliers['Acme']['last_purchase_at']);
        $this->assertNull($suppliers['Sin proveedor']['supplier_id']);

        // Un solo proveedor: sus meses.
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/suppliers?supplier_id={$acme->id}&from=2026-09-01&to=2026-10-31")
            ->assertJsonPath('totals.total_cop', 150000)
            ->assertJsonCount(2, 'by_month')
            ->assertJsonCount(1, 'by_supplier');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/suppliers?supplier_id=none&from=2026-09-01&to=2026-10-31')
            ->assertJsonPath('totals.total_cop', 20000)
            ->assertJsonPath('totals.supplier_count', 0);
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
