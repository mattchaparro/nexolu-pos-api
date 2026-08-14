<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\CashClosing;
use App\Models\Expense;
use App\Models\Layaway;
use App\Models\LayawayPayment;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\ServiceOrder;
use App\Models\ServicePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(?Business $business = null): array
    {
        $business ??= Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return [$business, $admin];
    }

    // ─── daily ────────────────────────────────────────────────────────────────

    public function test_daily_summary_returns_expected_structure(): void
    {
        [$business, $admin] = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/daily?date='.today()->toDateString());

        $response->assertOk()
            ->assertJsonStructure([
                'date', 'total_sales', 'closed_sales_revenue', 'sales_count',
                'open_count', 'open_total', 'total_all', 'payment_breakdown',
                'top_products', 'recent_sales', 'open_sales',
            ]);
    }

    public function test_daily_summary_counts_closed_sales_for_the_date(): void
    {
        [$business, $admin] = $this->admin();
        $date = '2026-01-15';

        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'total' => 50000,
            'is_credit' => false,
            'is_non_revenue' => false,
            'closed_at' => $date.' 10:00:00',
        ]);
        // venta de otro dia no debe aparecer
        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'total' => 20000,
            'is_credit' => false,
            'is_non_revenue' => false,
            'closed_at' => '2026-01-14 10:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/sales/daily?date={$date}");

        $response->assertOk()
            ->assertJsonPath('sales_count', 1)
            ->assertJsonPath('closed_sales_revenue', 50000);
    }

    public function test_daily_summary_requires_reports_sales_permission(): void
    {
        [$business, $admin] = $this->admin();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/reports/sales/daily')
            ->assertForbidden();
    }

    public function test_daily_summary_breaks_down_income_by_channel_and_payment_method(): void
    {
        [$business, $admin] = $this->admin();
        $date = today()->toDateString();

        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 40000,
            'is_credit' => false, 'is_non_revenue' => false, 'payment_method' => 'cash',
            'closed_at' => $date.' 09:00:00',
        ]);

        Receivable::factory()->for($business)->paid()->create(['amount' => 10000, 'payment_method' => 'transfer']);

        $order = ServiceOrder::factory()->for($business)->create();
        ServicePayment::factory()->for($order, 'order')->create(['business_id' => $business->id, 'amount' => 5000, 'payment_method' => 'cash']);

        $layaway = Layaway::factory()->for($business)->create();
        LayawayPayment::factory()->for($layaway, 'layaway')->create(['business_id' => $business->id, 'amount' => 7000, 'payment_method' => 'transfer']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/sales/daily?date={$date}")
            ->assertOk();

        $channels = collect($response->json('channels'))->keyBy('key');

        // json_decode devuelve int cuando el float no tiene parte decimal
        // (mismo comportamiento documentado en DashboardTest).
        $response->assertJsonPath('total_sales', 62000);
        $this->assertSame(40000, $channels['sales']['total']);
        $this->assertSame(1, $channels['sales']['count']);
        $this->assertSame(5000, $channels['services']['total']);
        $this->assertSame(7000, $channels['layaways']['total']);
        $this->assertSame(10000, $channels['receivables']['total']);

        // El canal "ventas" solo debe traer efectivo en su desglose; el de
        // apartados solo transferencia - no deben mezclarse entre canales
        // (esto no existia en ningun reporte del legacy).
        $salesByMethod = collect($channels['sales']['by_payment_method'])->keyBy('id');
        $this->assertSame(40000, $salesByMethod['cash']['total']);
        $layawaysByMethod = collect($channels['layaways']['by_payment_method'])->keyBy('id');
        $this->assertSame(7000, $layawaysByMethod['transfer']['total']);
        $this->assertSame(0, $layawaysByMethod['cash']['total']);
    }

    public function test_daily_summary_supports_a_date_range_via_date_from_and_date_to(): void
    {
        [$business, $admin] = $this->admin();

        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 10000,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-02-01 10:00:00',
        ]);
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 20000,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-02-03 10:00:00',
        ]);
        // fuera del rango solicitado
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 99999,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-02-10 10:00:00',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/daily?date_from=2026-02-01&date_to=2026-02-03')
            ->assertOk()
            ->assertJsonPath('sales_count', 2)
            ->assertJsonPath('closed_sales_revenue', 30000)
            ->assertJsonPath('date_from', '2026-02-01')
            ->assertJsonPath('date_to', '2026-02-03');
    }

    public function test_daily_summary_computes_net_as_income_minus_expenses(): void
    {
        [$business, $admin] = $this->admin();
        $date = today()->toDateString();

        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 50000,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => $date.' 09:00:00',
        ]);
        Expense::factory()->for($business)->create(['value' => 8000, 'scope' => 'operacional', 'date' => $date]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/sales/daily?date={$date}")
            ->assertOk()
            ->assertJsonPath('total_sales', 50000)
            ->assertJsonPath('total_expenses', 8000)
            ->assertJsonPath('net', 42000);
    }

    // ─── history ──────────────────────────────────────────────────────────────

    public function test_sales_history_returns_paginated_sales_within_range(): void
    {
        [$business, $admin] = $this->admin();

        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'total' => 80000,
            'is_credit' => false,
            'is_non_revenue' => false,
            'closed_at' => '2026-03-01 12:00:00',
        ]);
        // fuera de rango
        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'total' => 10000,
            'is_credit' => false,
            'is_non_revenue' => false,
            'closed_at' => '2026-04-01 12:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/history?from=2026-03-01&to=2026-03-31');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure(['data', 'meta', 'payment_method_options']);
    }

    public function test_sales_history_export_returns_csv(): void
    {
        [$business, $admin] = $this->admin();

        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'total' => 30000,
            'is_credit' => false,
            'is_non_revenue' => false,
            'closed_at' => '2026-03-05 09:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/reports/sales/history/export?from=2026-03-01&to=2026-03-31');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    // ─── by-seller ────────────────────────────────────────────────────────────

    public function test_sales_by_seller_groups_by_closed_by_user(): void
    {
        [$business, $admin] = $this->admin();
        $seller = User::factory()->create(['business_id' => $business->id]);

        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'total' => 60000,
            'is_credit' => false,
            'is_non_revenue' => false,
            'closed_by_user_id' => $seller->id,
            'closed_at' => '2026-03-10 15:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/sales/by-seller?from=2026-03-01&to=2026-03-31');

        $response->assertOk()
            ->assertJsonPath('totals.sales_count', 1)
            ->assertJsonPath('totals.gross_total', 60000)
            ->assertJsonCount(1, 'sellers');
    }

    public function test_by_seller_export_returns_csv(): void
    {
        [, $admin] = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/reports/sales/by-seller/export?from=2026-03-01&to=2026-03-31');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    // ─── cash closings ────────────────────────────────────────────────────────

    public function test_cash_closings_report_requires_cash_closing_feature(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['cash_closing' => false]]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/cash-closings')
            ->assertForbidden();
    }

    public function test_cash_closings_report_returns_closings_in_date_range(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['cash_closing' => true]]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        CashClosing::factory()->create([
            'business_id' => $business->id,
            'date' => '2026-03-10',
            'total_sales' => 100000,
            'actual_cash' => 80000,
            'expected_cash' => 80000,
            'difference' => 0,
        ]);
        // otro mes - no debe aparecer
        CashClosing::factory()->create([
            'business_id' => $business->id,
            'date' => '2026-02-10',
            'total_sales' => 50000,
            'actual_cash' => 50000,
            'expected_cash' => 50000,
            'difference' => 0,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/cash-closings?month=2026-03');

        $response->assertOk()
            ->assertJsonCount(1, 'closings')
            ->assertJsonPath('closings.0.total_sales', 100000);
    }

    public function test_cash_closings_export_returns_csv(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['cash_closing' => true]]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/reports/cash-closings/export?month=2026-03');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    // ─── tenant isolation ─────────────────────────────────────────────────────

    public function test_daily_summary_isolates_by_business(): void
    {
        [$business, $admin] = $this->admin();
        $otherBusiness = Business::factory()->create();
        $date = '2026-02-01';

        Sale::factory()->create([
            'business_id' => $otherBusiness->id,
            'status' => 'closed',
            'total' => 99000,
            'is_credit' => false,
            'is_non_revenue' => false,
            'closed_at' => $date.' 10:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/sales/daily?date={$date}");

        $response->assertOk()->assertJsonPath('sales_count', 0);
    }
}
