<?php

namespace Tests\Feature\Services;

use App\Models\Business;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServiceOrder;
use App\Models\ServicePayment;
use App\Models\User;
use App\Services\ManagerialAccountingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ManagerialAccountingServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): ManagerialAccountingService
    {
        return app(ManagerialAccountingService::class);
    }

    public function test_monthly_report_sums_income_from_direct_sales_receivables_and_service_payments(): void
    {
        $business = Business::factory()->create();

        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 100000,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-03-10 12:00:00',
        ]);
        // Venta a credito: no cuenta como ingreso directo, solo cuando el fiado se cobra.
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 999999,
            'is_credit' => true, 'is_non_revenue' => false, 'closed_at' => '2026-03-10 12:00:00',
        ]);
        // Venta no-revenue (p.ej. cortesia): no cuenta.
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 999999,
            'is_credit' => false, 'is_non_revenue' => true, 'closed_at' => '2026-03-10 12:00:00',
        ]);
        // Fuera del mes consultado.
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 999999,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-02-28 12:00:00',
        ]);

        Receivable::factory()->paid()->create(['business_id' => $business->id, 'amount' => 20000, 'paid_at' => '2026-03-05 09:00:00']);
        ServicePayment::factory()->create(['business_id' => $business->id, 'amount' => 15000, 'created_at' => '2026-03-12 09:00:00']);

        Expense::factory()->create(['business_id' => $business->id, 'value' => 30000, 'date' => '2026-03-08']);

        $report = $this->service()->monthlyReport($business->id, 2026, 3);

        $this->assertSame(135000.0, $report['income']); // 100000 + 20000 + 15000
        $this->assertSame(30000.0, $report['expenses']);
        $this->assertSame(105000.0, $report['net_result']);
        $this->assertSame(1, $report['sales_count']);
        $this->assertSame(1, $report['expenses_count']);
        $this->assertSame(20000.0, $report['receivables_collected']);
        $this->assertFalse($report['is_closed']);
        $this->assertNull($report['closed_at']);
    }

    public function test_monthly_report_reflects_a_previous_close(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);

        $this->service()->closeMonth($business->id, $admin->id, 2026, 3, 'Cerrado sin novedad');

        $report = $this->service()->monthlyReport($business->id, 2026, 3);

        $this->assertTrue($report['is_closed']);
        $this->assertNotNull($report['closed_at']);
        $this->assertSame($admin->name, $report['closed_by']);
        $this->assertSame('Cerrado sin novedad', $report['closed_notes']);
    }

    public function test_monthly_lines_lists_income_and_expense_lines_with_descriptions(): void
    {
        $business = Business::factory()->create();

        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 50000,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-03-10 12:00:00',
            'invoice_number' => 'F-001', 'customer_name' => 'Ana',
        ]);

        $client = Client::factory()->create(['business_id' => $business->id, 'name' => 'Cliente VIP']);
        $order = ServiceOrder::factory()->create(['business_id' => $business->id, 'client_id' => $client->id, 'service_name' => 'Corte']);
        ServicePayment::factory()->create(['business_id' => $business->id, 'service_order_id' => $order->id, 'amount' => 15000, 'created_at' => '2026-03-12 09:00:00']);

        $type = ExpenseType::factory()->create(['business_id' => $business->id, 'name' => 'Arriendo']);
        Expense::factory()->create(['business_id' => $business->id, 'type_id' => $type->id, 'value' => 30000, 'date' => '2026-03-08', 'description' => 'Arriendo local']);

        $lines = $this->service()->monthlyLines($business->id, 2026, 3);

        $this->assertCount(2, $lines['income_lines']);
        $saleLine = collect($lines['income_lines'])->firstWhere('type', 'sale');
        $this->assertSame('F-001 · Ana', $saleLine['description']);
        $servicePaymentLine = collect($lines['income_lines'])->firstWhere('type', 'service_payment');
        $this->assertSame('Servicio: Corte · Cliente VIP', $servicePaymentLine['description']);

        $this->assertCount(1, $lines['expense_lines']);
        $this->assertSame('Arriendo local', $lines['expense_lines'][0]['description']);
        $this->assertSame('Arriendo', $lines['expense_lines'][0]['type_name']);
    }

    public function test_product_profit_lines_separates_costed_from_uncosted_products(): void
    {
        $business = Business::factory()->create();
        $sale = Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed',
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-03-10 12:00:00',
        ]);

        $costed = Product::factory()->create(['business_id' => $business->id, 'name' => 'Con costo', 'cost_price' => 1000]);
        SaleItem::factory()->create([
            'sale_id' => $sale->id, 'product_id' => $costed->id,
            'quantity' => 2, 'unit_price' => 5000, 'unit_cost_at_sale' => 1000,
            'subtotal' => 10000, 'discount_amount' => 0,
        ]);

        $uncosted = Product::factory()->create(['business_id' => $business->id, 'name' => 'Sin costo', 'cost_price' => 0]);
        SaleItem::factory()->create([
            'sale_id' => $sale->id, 'product_id' => $uncosted->id,
            'quantity' => 1, 'unit_price' => 8000, 'unit_cost_at_sale' => 0,
            'subtotal' => 8000, 'discount_amount' => 0,
        ]);

        $lines = $this->service()->monthlyLines($business->id, 2026, 3)['product_profit_lines'];

        $this->assertCount(1, $lines['lines']);
        $this->assertSame('Con costo', $lines['lines'][0]['name']);
        $this->assertSame(10000.0, $lines['lines'][0]['revenue']);
        $this->assertSame(2000.0, $lines['lines'][0]['cost_total']);
        $this->assertSame(8000.0, $lines['lines'][0]['profit']);

        $this->assertSame(1, $lines['uncosted']['products_count']);
        $this->assertSame('Sin costo', $lines['uncosted']['lines'][0]['name']);
        $this->assertSame(8000.0, $lines['uncosted']['total_revenue']);
    }

    public function test_annual_report_aggregates_the_twelve_months(): void
    {
        $business = Business::factory()->create();

        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 40000,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-01-15 12:00:00',
        ]);
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 60000,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-06-15 12:00:00',
        ]);
        Expense::factory()->create(['business_id' => $business->id, 'value' => 10000, 'date' => '2026-06-05']);

        $annual = $this->service()->annualReport($business->id, 2026);

        $this->assertCount(12, $annual['months']);
        $this->assertSame(100000.0, $annual['income_total']);
        $this->assertSame(10000.0, $annual['expenses_total']);
        $this->assertSame(90000.0, $annual['net_total']);

        $june = collect($annual['months'])->firstWhere('month', 6);
        $this->assertSame(60000.0, $june['income']);
        $this->assertSame(10000.0, $june['expenses']);
    }

    public function test_close_month_persists_and_freezes_the_report_snapshot(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 50000,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-03-10 12:00:00',
        ]);

        $closing = $this->service()->closeMonth($business->id, $admin->id, 2026, 3, 'Todo cuadrado');

        $this->assertDatabaseHas('accounting_period_closings', [
            'business_id' => $business->id, 'year' => 2026, 'month' => 3,
            'status' => 'closed', 'notes' => 'Todo cuadrado', 'closed_by_user_id' => $admin->id,
        ]);
        $this->assertSame(50000, $closing->summary['income']);

        // Un segundo cierre del mismo mes actualiza el mismo registro, no crea otro.
        $second = $this->service()->closeMonth($business->id, $admin->id, 2026, 3, 'Ajustado');
        $this->assertSame($closing->id, $second->id);
        $this->assertDatabaseCount('accounting_period_closings', 1);
    }
}
