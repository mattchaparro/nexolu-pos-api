<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(?Business $business = null): array
    {
        $business ??= Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return [$business, $admin];
    }

    public function test_monthly_report_returns_totals_and_lines(): void
    {
        [$business, $admin] = $this->admin();
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 70000,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-03-10 12:00:00',
        ]);
        Expense::factory()->create(['business_id' => $business->id, 'value' => 20000, 'date' => '2026-03-05']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/accounting/monthly?year=2026&month=3');

        $response->assertOk()
            ->assertJsonPath('year', 2026)
            ->assertJsonPath('month', 3)
            ->assertJsonPath('income', 70000)
            ->assertJsonPath('expenses', 20000)
            ->assertJsonPath('net_result', 50000)
            ->assertJsonCount(1, 'income_lines')
            ->assertJsonCount(1, 'expense_lines');
    }

    public function test_annual_report_returns_twelve_months(): void
    {
        [, $admin] = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/accounting/annual?year=2026');

        $response->assertOk()->assertJsonPath('year', 2026)->assertJsonCount(12, 'months');
    }

    public function test_close_month_creates_a_closing_and_it_appears_in_the_list(): void
    {
        [, $admin] = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/accounting/close-month', ['year' => 2026, 'month' => 3, 'notes' => 'Todo cuadrado'])
            ->assertCreated()
            ->assertJsonPath('year', 2026)
            ->assertJsonPath('month', 3)
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('closed_by', $admin->name);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/accounting/closings')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.notes', 'Todo cuadrado');
    }

    public function test_close_month_validates_month_range(): void
    {
        [, $admin] = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/accounting/close-month', ['year' => 2026, 'month' => 13])
            ->assertStatus(422);
    }

    public function test_monthly_export_returns_a_csv_file(): void
    {
        [$business, $admin] = $this->admin();
        Sale::factory()->create([
            'business_id' => $business->id, 'status' => 'closed', 'total' => 70000,
            'is_credit' => false, 'is_non_revenue' => false, 'closed_at' => '2026-03-10 12:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/accounting/monthly/export?year=2026&month=3');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_a_business_without_the_feature_is_blocked(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['managerial_accounting' => false]]);
        [, $admin] = $this->admin($business);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/accounting/monthly')
            ->assertStatus(403);
    }

    public function test_an_employee_without_the_permission_is_rejected(): void
    {
        PermissionCatalog::sync();
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/accounting/monthly')
            ->assertStatus(403);
    }

    public function test_an_employee_with_the_direct_permission_is_allowed(): void
    {
        PermissionCatalog::sync();
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $employee->syncPermissions(['accounting.manage']);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/accounting/monthly')
            ->assertOk();
    }
}
