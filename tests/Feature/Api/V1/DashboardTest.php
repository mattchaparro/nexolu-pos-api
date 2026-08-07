<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\ServiceOrder;
use App\Models\ServicePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_today_summary_combines_sales_receivables_and_service_payments(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        // Cuenta: venta cerrada normal de hoy.
        Sale::factory()->for($business)->create(['status' => 'closed', 'total' => 50000]);
        // No cuenta: venta a credito (aunque este cerrada).
        Sale::factory()->for($business)->create(['status' => 'closed', 'total' => 20000, 'is_credit' => true]);
        // No cuenta: venta no-revenue.
        Sale::factory()->for($business)->create(['status' => 'closed', 'total' => 15000, 'is_non_revenue' => true]);
        // No cuenta en today_sales/today_count: sigue abierta, pero SI cuenta en open_tabs_total.
        Sale::factory()->for($business)->create(['status' => 'open', 'total' => 30000]);

        // Cuenta: fiado cobrado hoy.
        Receivable::factory()->for($business)->paid()->create(['amount' => 10000]);
        // No cuenta: fiado pendiente (no cobrado).
        Receivable::factory()->for($business)->create(['amount' => 8000, 'balance' => 8000]);

        // Cuenta: pago de servicio de hoy.
        $order = ServiceOrder::factory()->for($business)->create();
        ServicePayment::factory()->for($order, 'order')->create(['business_id' => $business->id, 'amount' => 5000]);

        // Cuenta: gasto operacional de hoy.
        Expense::factory()->for($business)->create(['value' => 7000, 'scope' => 'operacional', 'date' => now()->toDateString()]);
        // No cuenta: gasto administrativo (no operacional), aunque sea de hoy.
        Expense::factory()->for($business)->create(['value' => 3000, 'scope' => 'administrativo', 'date' => now()->toDateString()]);
        // No cuenta: gasto operacional pero de otro dia.
        Expense::factory()->for($business)->create(['value' => 9000, 'scope' => 'operacional', 'date' => now()->subDay()->toDateString()]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk();

        // json_encode omite el ".0" de floats sin parte decimal, por eso se
        // comparan como enteros - el valor real ya es float en PHP (ver DashboardService).
        $response->assertJsonPath('today_sales', 65000); // 50000 venta + 10000 fiado + 5000 servicio
        $response->assertJsonPath('today_count', 1);
        $response->assertJsonPath('open_tabs_total', 30000);
        $response->assertJsonPath('receivables_enabled', true);
        $response->assertJsonPath('pending_receivables', 8000);
        $response->assertJsonPath('expenses_enabled', true);
        $response->assertJsonPath('today_expenses', 7000);
    }

    public function test_disabled_features_zero_out_their_stats(): void
    {
        $business = Business::factory()->create([
            'feature_flags' => ['receivables' => false, 'expenses' => false],
        ]);
        $user = User::factory()->create(['business_id' => $business->id]);

        Receivable::factory()->for($business)->create(['amount' => 8000, 'balance' => 8000]);
        Expense::factory()->for($business)->create(['value' => 7000, 'scope' => 'operacional']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('receivables_enabled', false)
            ->assertJsonPath('pending_receivables', 0)
            ->assertJsonPath('expenses_enabled', false)
            ->assertJsonPath('today_expenses', 0);
    }

    public function test_guest_cannot_access_dashboard_summary(): void
    {
        $this->getJson('/api/v1/dashboard/summary')->assertUnauthorized();
    }
}
