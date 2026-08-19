<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\CashShift;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El usuario explicitamente pidio esto al migrar turnos de caja: un empleado
 * no puede vender si no ha abierto su turno. Puerto de
 * routes/employee.php::cash_shift.sales del legacy (tests/Feature/Employee/
 * StaleCashShiftTest.php alla) - mismos casos limite: el dueño/admin queda
 * exento, y bloquear por turno arrastrado de un dia anterior va detras de un
 * flag apagado por defecto porque impedir vender es lo mas disruptivo que
 * existe.
 */
class EnsureCashShiftOpenForSalesTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function business(array $overrides = []): Business
    {
        return Business::factory()->create(array_merge(['feature_flags' => [
            'cash_closing' => true,
            'shift_daily_close_required' => false,
        ]], $overrides));
    }

    private function cashier(Business $business): User
    {
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->syncPermissions(['cash_shift.manage']);

        return $user->fresh();
    }

    private function sell(User $user, Business $business)
    {
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
    }

    public function test_admin_can_sell_without_an_open_shift(): void
    {
        $business = $this->business();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->sell($admin, $business)->assertCreated();
    }

    public function test_cashier_without_an_open_shift_cannot_sell(): void
    {
        $business = $this->business();
        $cashier = $this->cashier($business);

        $this->sell($cashier, $business)->assertStatus(422);
    }

    public function test_cashier_with_an_open_shift_today_can_sell(): void
    {
        $business = $this->business();
        $cashier = $this->cashier($business);
        CashShift::factory()->create(['business_id' => $business->id, 'user_id' => $cashier->id]);

        $this->sell($cashier, $business)->assertCreated();
    }

    public function test_an_employee_without_cash_shift_manage_is_not_gated_at_all(): void
    {
        $business = $this->business();
        $employee = User::factory()->create(['business_id' => $business->id]);

        $this->sell($employee, $business)->assertCreated();
    }

    public function test_no_block_when_the_business_does_not_have_the_cash_closing_feature(): void
    {
        $business = $this->business(['feature_flags' => ['cash_closing' => false]]);
        $cashier = $this->cashier($business);

        $this->sell($cashier, $business)->assertCreated();
    }

    public function test_a_shift_carried_over_from_yesterday_only_warns_without_the_flag(): void
    {
        Carbon::setTestNow(now()->startOfDay()->addHours(14));
        $business = $this->business();
        $cashier = $this->cashier($business);
        CashShift::factory()->create([
            'business_id' => $business->id,
            'user_id' => $cashier->id,
            'opened_at' => now()->subDay()->startOfDay()->addHours(9),
        ]);

        $this->sell($cashier, $business)->assertCreated();
    }

    public function test_a_shift_carried_over_from_yesterday_blocks_sales_when_the_flag_is_on(): void
    {
        Carbon::setTestNow(now()->startOfDay()->addHours(14));
        $business = $this->business(['feature_flags' => [
            'cash_closing' => true,
            'shift_daily_close_required' => true,
        ]]);
        $cashier = $this->cashier($business);
        CashShift::factory()->create([
            'business_id' => $business->id,
            'user_id' => $cashier->id,
            'opened_at' => now()->subDay()->startOfDay()->addHours(9),
        ]);

        $this->sell($cashier, $business)->assertStatus(422);
    }

    /** Caso del negocio nocturno: 10h desde la apertura, no arrastrado - ni con el flag encendido debe bloquear. */
    public function test_a_legitimate_overnight_shift_sells_normally_even_with_the_flag_on(): void
    {
        Carbon::setTestNow(now()->startOfDay()->addHours(3));
        $business = $this->business(['feature_flags' => [
            'cash_closing' => true,
            'shift_daily_close_required' => true,
        ]]);
        $cashier = $this->cashier($business);
        CashShift::factory()->create([
            'business_id' => $business->id,
            'user_id' => $cashier->id,
            'opened_at' => now()->subDay()->startOfDay()->addHours(17),
        ]);

        $this->sell($cashier, $business)->assertCreated();
    }
}
