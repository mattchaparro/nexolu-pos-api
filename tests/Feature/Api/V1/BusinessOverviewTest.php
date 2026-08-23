<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * "Mi negocio" tiene su propio gate para la seccion de margen:
 * reports.business_overview (todo el reporte) no basta para ver
 * rentabilidad, hace falta accounting.manage (mismo permiso que
 * Contabilidad gerencial) porque expone costo/utilidad real.
 */
class BusinessOverviewTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(?Business $business = null): array
    {
        $business ??= Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return [$business, $admin];
    }

    private function seedCostedSale(Business $business): void
    {
        $product = Product::factory()->create(['business_id' => $business->id]);
        $sale = Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'is_credit' => false,
            'is_non_revenue' => false, 'closed_at' => now(),
        ]);
        SaleItem::factory()->create([
            'sale_id' => $sale->id, 'product_id' => $product->id,
            'quantity' => 1, 'subtotal' => 10000, 'discount_amount' => 0, 'unit_cost_at_sale' => 4000,
        ]);
    }

    public function test_admin_sees_the_profit_section(): void
    {
        [$business, $admin] = $this->admin();
        $this->seedCostedSale($business);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/business-overview?year='.now()->year.'&month='.now()->month);

        $response->assertOk()->assertJsonPath('period.profit.revenue', 10000);
    }

    public function test_an_employee_without_accounting_manage_does_not_see_the_profit_section(): void
    {
        PermissionCatalog::sync();
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $employee->syncPermissions(['reports.business_overview']);
        $this->seedCostedSale($business);

        $response = $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/sales/business-overview?year='.now()->year.'&month='.now()->month);

        $response->assertOk()->assertJsonPath('period.profit', null);
    }

    public function test_an_employee_with_accounting_manage_sees_the_profit_section(): void
    {
        PermissionCatalog::sync();
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $employee->syncPermissions(['reports.business_overview', 'accounting.manage']);
        $this->seedCostedSale($business);

        $response = $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/sales/business-overview?year='.now()->year.'&month='.now()->month);

        $response->assertOk()->assertJsonPath('period.profit.revenue', 10000);
    }

    public function test_an_employee_without_reports_business_overview_is_rejected_entirely(): void
    {
        PermissionCatalog::sync();
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/sales/business-overview')
            ->assertStatus(403);
    }

    public function test_an_employee_with_only_the_old_reports_sales_permission_is_rejected(): void
    {
        // reports.sales dejo de cubrir Mi negocio - ahora hace falta
        // reports.business_overview, aparte (ver PermissionCatalog).
        PermissionCatalog::sync();
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $employee->syncPermissions(['reports.sales']);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/sales/business-overview')
            ->assertStatus(403);
    }
}
