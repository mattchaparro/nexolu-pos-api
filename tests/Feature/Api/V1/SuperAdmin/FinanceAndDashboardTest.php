<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\Business;
use App\Models\SaasSubscriptionPayment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class FinanceAndDashboardTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_finance_summary_sums_payments_for_the_requested_month(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create(['paid_until' => now()->addDays(20), 'subscription_plan' => 'full']);
        SaasSubscriptionPayment::factory()->create([
            'business_id' => $business->id,
            'amount_cop' => 65000,
            'paid_at' => now()->startOfMonth()->addDays(2),
        ]);
        SaasSubscriptionPayment::factory()->create([
            'business_id' => $business->id,
            'amount_cop' => 20000,
            'paid_at' => now()->subMonths(2), // fuera del mes consultado
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/finance?year='.now()->year.'&month='.now()->month);

        $response->assertOk()
            ->assertJsonPath('summary.income.total_cop', 65000)
            ->assertJsonPath('summary.income.count', 1)
            ->assertJsonPath('mrr_cop', 85000);
    }

    public function test_dashboard_returns_business_counts_and_expiring_businesses(): void
    {
        $admin = $this->superadmin();
        Business::factory()->create(['active' => true, 'paid_until' => now()->addDays(3)]);
        Business::factory()->create(['active' => true, 'trial_ends_at' => now()->addDays(5)]);
        Business::factory()->create(['active' => false]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/dashboard');

        $response->assertOk()
            ->assertJsonPath('stats.total_businesses', 3)
            ->assertJsonPath('stats.paid', 1)
            ->assertJsonCount(2, 'expiring_businesses');
    }
}
