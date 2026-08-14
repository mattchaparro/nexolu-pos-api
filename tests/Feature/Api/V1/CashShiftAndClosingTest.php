<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\CashClosing;
use App\Models\CashShift;
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

class CashShiftAndClosingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_user_can_open_and_close_a_shift(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $open = $this->actingAs($user, 'sanctum')->postJson('/api/v1/cash-shifts', [
            'opening_cash' => 50000,
            'opening_note' => 'apertura',
        ]);
        $open->assertCreated()->assertJsonPath('is_open', true);

        $shiftId = $open->json('id');

        $close = $this->actingAs($user, 'sanctum')->postJson("/api/v1/cash-shifts/{$shiftId}/close", [
            'counted_cash' => 50000,
        ]);

        $close->assertOk()
            ->assertJsonPath('is_open', false)
            ->assertJsonPath('expected_cash', '50000.00')
            ->assertJsonPath('difference', '0.00');
    }

    public function test_a_user_cannot_open_a_second_shift_while_one_is_open(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        CashShift::factory()->create(['business_id' => $business->id, 'user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/cash-shifts', ['opening_cash' => 10000])
            ->assertStatus(422);
    }

    public function test_a_shift_cannot_be_opened_when_todays_cash_closing_already_exists(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        CashClosing::factory()->create(['business_id' => $business->id, 'date' => now()->toDateString()]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/cash-shifts', ['opening_cash' => 10000])
            ->assertStatus(422);
    }

    public function test_only_the_shifts_owner_can_close_it(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id]);
        $owner->assignRole('admin');
        $otherUser = User::factory()->create(['business_id' => $business->id]);
        $otherUser->assignRole('admin');
        $shift = CashShift::factory()->create(['business_id' => $business->id, 'user_id' => $owner->id]);

        $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/v1/cash-shifts/{$shift->id}/close", ['counted_cash' => 0])
            ->assertStatus(422);
    }

    /**
     * El efectivo esperado de un cierre debe sumar las "3+1 fuentes de ingreso":
     * ventas en efectivo + fiados cobrados en efectivo + abonos a servicios en
     * efectivo + abonos a apartados en efectivo - un cierre que solo sume
     * ventas subestima el efectivo real que entro a la caja.
     */
    public function test_expected_cash_sums_the_three_plus_one_revenue_sources(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $shift = $this->actingAs($user, 'sanctum')->postJson('/api/v1/cash-shifts', [
            'opening_cash' => 10000,
        ])->json();

        Sale::factory()->create([
            'business_id' => $business->id,
            'payment_method' => 'cash',
            'total' => 20000,
            'status' => 'closed',
            'closed_by_user_id' => $user->id,
        ]);

        $order = ServiceOrder::factory()->create(['business_id' => $business->id]);
        ServicePayment::factory()->create([
            'service_order_id' => $order->id,
            'business_id' => $business->id,
            'amount' => 5000,
            'payment_method' => 'cash',
            'recorded_by_user_id' => $user->id,
        ]);

        $layaway = Layaway::factory()->create(['business_id' => $business->id]);
        LayawayPayment::factory()->create([
            'layaway_id' => $layaway->id,
            'business_id' => $business->id,
            'amount' => 3000,
            'payment_method' => 'cash',
            'recorded_by_user_id' => $user->id,
        ]);

        Receivable::factory()->paid()->create([
            'business_id' => $business->id,
            'collected_by_user_id' => $user->id,
        ]);
        $receivable = Receivable::where('business_id', $business->id)->first();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/cash-shifts/{$shift['id']}/close", ['counted_cash' => 999999]);

        $response->assertOk();
        // round(): mismo tratamiento que CashClosingService::buildPaymentBreakdown -
        // sin esto, el float crudo de Receivable::factory() (randomFloat) puede
        // diferir en el ultimo decimal por precision binaria, no por un error real.
        $expected = round(10000 + 20000 + 5000 + 3000 + (float) $receivable->amount, 2);
        $this->assertEquals($expected, (float) $response->json('expected_cash'));
    }

    public function test_current_endpoint_returns_the_open_shift_with_a_live_preview(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        CashShift::factory()->create(['business_id' => $business->id, 'user_id' => $user->id, 'opening_cash' => 20000]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/cash-shifts/current');

        $response->assertOk()
            ->assertJsonPath('shift.opening_cash', '20000.00')
            ->assertJsonPath('preview_totals.opening_cash', 20000);
    }

    public function test_current_endpoint_returns_null_when_there_is_no_open_shift(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/cash-shifts/current')
            ->assertOk()
            ->assertJsonPath('shift', null)
            ->assertJsonPath('preview_totals', null);
    }

    public function test_closing_cash_auto_closes_catchable_open_shifts(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');
        $cashier = User::factory()->create(['business_id' => $business->id]);
        $shift = CashShift::factory()->create([
            'business_id' => $business->id,
            'user_id' => $cashier->id,
            'opening_cash' => 10000,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/cash-closings', [
            'date' => now()->toDateString(),
            'opening_cash' => 0,
            'actual_cash' => 0,
            'base_for_next_day' => 0,
        ]);

        $response->assertCreated();

        $shift->refresh();
        $this->assertNotNull($shift->closed_at);
        $this->assertSame(CashShift::CLOSED_VIA_AUTO_CASH_CLOSING, $shift->closed_via);
        $this->assertSame($response->json('id'), $shift->closed_by_cash_closing_id);
        $this->assertSame($admin->id, $shift->closed_by_user_id);
    }

    /**
     * Un turno que arrastra de ayer pero cuyo cajero ya vendio HOY no debe
     * auto-cerrarse al cerrar la caja de AYER: cerrarlo dejaria fuera las
     * ventas de hoy y expulsaria al cajero del POS a mitad de jornada.
     */
    public function test_a_shift_still_in_use_today_is_not_auto_closed_by_yesterdays_cash_closing(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');
        $cashier = User::factory()->create(['business_id' => $business->id]);
        $shift = CashShift::factory()->create([
            'business_id' => $business->id,
            'user_id' => $cashier->id,
            'opened_at' => now()->subDay(),
        ]);

        // El scope mide por sales.user_id + sales.created_at (quien creo la
        // venta, no quien la cobro): es lo unico que toda venta trae, incluso
        // las que un turno crea y otro cierra.
        Sale::factory()->create([
            'business_id' => $business->id,
            'user_id' => $cashier->id,
            'status' => 'closed',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/cash-closings', [
            'date' => now()->subDay()->toDateString(),
            'opening_cash' => 0,
            'actual_cash' => 0,
            'base_for_next_day' => 0,
        ])->assertCreated();

        $shift->refresh();
        $this->assertNull($shift->closed_at);
    }

    public function test_a_duplicate_cash_closing_for_the_same_date_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        CashClosing::factory()->create(['business_id' => $business->id, 'date' => now()->toDateString()]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/cash-closings', [
            'date' => now()->toDateString(),
            'opening_cash' => 0,
            'actual_cash' => 0,
            'base_for_next_day' => 0,
        ])->assertStatus(422);
    }

    public function test_undo_is_restricted_to_the_same_day_it_was_made(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $closing = CashClosing::factory()->create([
            'business_id' => $business->id,
            'date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/cash-closings/{$closing->id}/undo")
            ->assertStatus(422);

        $this->assertDatabaseHas('cash_closings', ['id' => $closing->id]);
    }

    public function test_undo_reopens_the_shifts_it_auto_closed_and_deletes_the_closing(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');
        $cashier = User::factory()->create(['business_id' => $business->id]);
        $shift = CashShift::factory()->create(['business_id' => $business->id, 'user_id' => $cashier->id]);

        $closing = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/cash-closings', [
            'date' => now()->toDateString(),
            'opening_cash' => 0,
            'actual_cash' => 0,
            'base_for_next_day' => 0,
        ])->json();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/cash-closings/{$closing['id']}/undo")
            ->assertNoContent();

        $shift->refresh();
        $this->assertNull($shift->closed_at);
        $this->assertNull($shift->closed_by_cash_closing_id);
        $this->assertDatabaseMissing('cash_closings', ['id' => $closing['id']]);
    }

    public function test_updating_a_closed_shift_recomputes_expected_cash_and_difference(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');
        $shift = CashShift::factory()->closed()->create([
            'business_id' => $business->id,
            'opening_cash' => 50000,
            'total_cash' => 20000,
            'total_expenses' => 5000,
            'counted_cash' => 65000,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/v1/cash-shifts/{$shift->id}", [
            'opening_cash' => 60000,
            'counted_cash' => 75000,
        ]);

        $response->assertOk()
            ->assertJsonPath('expected_cash', '75000.00') // 60000 + 20000 - 5000
            ->assertJsonPath('difference', '0.00');
    }

    /**
     * Bug real: update()/destroy() de un turno de caja vivian bajo el mismo
     * cash_shift.manage que abrir/cerrar el turno propio - un cajero con ese
     * unico permiso podia reescribir o borrar el historial de caja de
     * cualquier compañero. Ahora requieren cash_shift.correct aparte.
     */
    public function test_updating_a_shift_requires_cash_shift_correct_permission(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->syncPermissions(['cash_shift.manage']);
        $shift = CashShift::factory()->closed()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/cash-shifts/{$shift->id}", ['opening_cash' => 60000])
            ->assertForbidden();
    }

    public function test_deleting_a_shift_requires_cash_shift_correct_permission(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->syncPermissions(['cash_shift.manage']);
        $shift = CashShift::factory()->closed()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/cash-shifts/{$shift->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('cash_shifts', ['id' => $shift->id]);
    }

    public function test_a_user_with_cash_shift_correct_can_update_and_delete_any_shift(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->syncPermissions(['cash_shift.correct']);
        $shift = CashShift::factory()->closed()->create([
            'business_id' => $business->id,
            'opening_cash' => 50000,
            'total_cash' => 20000,
            'total_expenses' => 5000,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/cash-shifts/{$shift->id}", ['opening_cash' => 60000, 'counted_cash' => 75000])
            ->assertOk()
            ->assertJsonPath('expected_cash', '75000.00');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/cash-shifts/{$shift->id}")
            ->assertNoContent();
    }

    public function test_operational_expenses_reduce_expected_cash(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        Expense::factory()->create([
            'business_id' => $business->id,
            'date' => now()->toDateString(),
            'value' => 7000,
            'scope' => 'operacional',
        ]);

        $shift = $this->actingAs($user, 'sanctum')->postJson('/api/v1/cash-shifts', [
            'opening_cash' => 10000,
        ])->json();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/cash-shifts/{$shift['id']}/close", ['counted_cash' => 3000]);

        $response->assertOk()->assertJsonPath('expected_cash', '3000.00'); // 10000 - 7000
    }

    public function test_user_can_only_see_their_business_cash_shifts_and_closings(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        CashShift::factory()->count(2)->create(['business_id' => $business->id]);
        CashShift::factory()->create(['business_id' => $otherBusiness->id]);
        CashClosing::factory()->create(['business_id' => $business->id]);
        CashClosing::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/cash-shifts')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/cash-closings')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_preview_returns_totals_and_shifts_that_would_be_auto_closed(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        CashShift::factory()->create(['business_id' => $business->id, 'opening_cash' => 15000]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/cash-closings/preview?date='.now()->toDateString());

        $response->assertOk()
            ->assertJsonPath('date', now()->toDateString())
            ->assertJsonCount(1, 'shifts_to_auto_close');
    }
}
