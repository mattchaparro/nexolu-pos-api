<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
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

    /**
     * Las compras se muestran pero no se restan: ya pesan en el margen como
     * costo de lo vendido (Las Banquitas, nov-2026, preguntaba por que no
     * salian como gasto).
     */
    public function test_purchases_are_shown_apart_and_the_profit_discounts_the_expenses(): void
    {
        [$business, $admin] = $this->admin();
        $this->seedCostedSale($business);
        $purchase = Purchase::factory()->create(['business_id' => $business->id, 'purchased_at' => now()->toDateString()]);
        PurchaseLine::factory()->create(['purchase_id' => $purchase->id, 'line_total_cop' => 250000]);
        Expense::factory()->create(['business_id' => $business->id, 'date' => now()->toDateString(), 'value' => 1500]);
        // El abono a una compra a credito crea un gasto ligado: no cuenta.
        Expense::factory()->create([
            'business_id' => $business->id, 'date' => now()->toDateString(), 'value' => 90000,
            'linkable_type' => Purchase::class, 'linkable_id' => $purchase->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/business-overview?year='.now()->year.'&month='.now()->month)
            ->assertOk()
            ->assertJsonPath('period.summary.purchases_total', 250000)
            ->assertJsonPath('period.summary.purchases_count', 1)
            ->assertJsonPath('period.summary.expenses_total', 1500)
            ->assertJsonPath('period.profit.profit', 6000)
            ->assertJsonPath('period.profit.profit_after_expenses', 4500);
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
