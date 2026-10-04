<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashClosing;
use App\Models\CashShift;
use App\Models\LogAction;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Origen: un descuadre real en el POS legacy (Central Cell, 17-sep-2026). El
 * cierre del 16 dejo $200.000 de base, el turno del 17 abrio declarando
 * $180.000, el turno cerro en $0 y los $20.000 aparecieron un dia despues en
 * el cierre de caja. Aca la apertura arrancaba en $0 y el preview del cierre
 * nunca comparaba las bases.
 *
 * La mitad de estos tests son de cuando NO avisar: una base sugerida
 * equivocada obliga a escribir notas sin sentido, y un aviso que salta siempre
 * se deja de leer. Y el POS nuevo agrega un caso que el legacy no tiene: la
 * base es de la caja de UNA sede.
 */
class CashShiftOpeningBaseTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Sin reloj fijo, abrir turnos "por la manana" corriendo la suite de
        // madrugada caeria en el futuro.
        Carbon::setTestNow(now()->setTime(14, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        BranchContext::forget();

        parent::tearDown();
    }

    /**
     * @return array{0: Business, 1: Branch, 2: User}
     */
    private function scenario(): array
    {
        $business = Business::factory()->create();
        $main = Branch::factory()->for($business)->main()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        return [$business, $main, $user];
    }

    private function closingFor(Business $business, Branch $branch, Carbon $date, float $baseForNextDay): CashClosing
    {
        return CashClosing::factory()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'date' => $date->toDateString(),
            'base_for_next_day' => $baseForNextDay,
        ]);
    }

    private function shiftFor(Business $business, Branch $branch, User $user, Carbon $openedAt, float $openingCash): CashShift
    {
        return CashShift::factory()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'opened_at' => $openedAt,
            'closed_at' => $openedAt->copy()->addHours(3),
            'opening_cash' => $openingCash,
        ]);
    }

    public function test_current_suggests_the_base_left_by_the_last_closing(): void
    {
        [$business, $main, $user] = $this->scenario();
        $this->closingFor($business, $main, now()->subDay(), 200000);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/cash-shifts/current')
            ->assertOk()
            ->assertJsonPath('shift', null)
            ->assertJsonPath('expected_opening.amount', 200000)
            ->assertJsonPath('expected_opening.closing_date', now()->subDay()->toDateString());
    }

    public function test_opening_with_a_different_base_and_no_note_is_rejected(): void
    {
        [$business, $main, $user] = $this->scenario();
        $this->closingFor($business, $main, now()->subDay(), 200000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/cash-shifts', ['opening_cash' => 180000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['opening_note']);

        $this->assertSame(0, CashShift::withoutGlobalScopes()->where('business_id', $business->id)->count());
    }

    public function test_opening_with_a_different_base_and_a_note_is_allowed_and_audited(): void
    {
        [$business, $main, $user] = $this->scenario();
        $this->closingFor($business, $main, now()->subDay(), 200000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/cash-shifts', [
                'opening_cash' => 180000,
                'opening_note' => 'Encontre 180 en la caja, le avise a Luis.',
            ])
            ->assertCreated();

        $audit = LogAction::where('action', 'cash_shift.opened')->latest('id')->first();
        $this->assertEquals(200000, $audit->details['expected_opening_cash']);
        $this->assertEquals(-20000, $audit->details['opening_difference']);
    }

    public function test_opening_with_the_expected_base_needs_no_note(): void
    {
        [$business, $main, $user] = $this->scenario();
        $this->closingFor($business, $main, now()->subDay(), 200000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/cash-shifts', ['opening_cash' => 200000])
            ->assertCreated();
    }

    /** Segundo turno del dia: la base es lo que conto el cajero anterior, no la del cierre. */
    public function test_the_base_is_what_the_previous_shift_counted(): void
    {
        [$business, $main, $user] = $this->scenario();
        $other = User::factory()->create(['business_id' => $business->id]);
        $this->closingFor($business, $main, now()->subDay(), 200000);
        $shift = $this->shiftFor($business, $main, $other, now()->startOfDay()->addHours(8), 200000);
        $shift->update(['counted_cash' => 350000]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/cash-shifts/current')
            ->assertJsonPath('expected_opening.amount', 350000)
            ->assertJsonPath('expected_opening.source', 'shift');
    }

    /** Sin cierres de por medio la base se acumula de dia en dia, turno tras turno. */
    public function test_the_base_accumulates_across_days_without_a_closing(): void
    {
        [$business, $main, $user] = $this->scenario();
        $this->closingFor($business, $main, now()->subDays(3), 100000);
        $first = $this->shiftFor($business, $main, $user, now()->subDays(2)->startOfDay()->addHours(9), 100000);
        $first->update(['counted_cash' => 250000]);
        $second = $this->shiftFor($business, $main, $user, now()->subDay()->startOfDay()->addHours(9), 250000);
        $second->update(['counted_cash' => 420000]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/cash-shifts/current')
            ->assertJsonPath('expected_opening.amount', 420000)
            ->assertJsonPath('expected_opening.source', 'shift');
    }

    /** El dueño cerro caja hoy: su base manda, no lo que conto el turno que se auto-cerro. */
    public function test_the_owner_base_wins_on_the_day_of_the_closing(): void
    {
        [$business, $main, $user] = $this->scenario();
        $this->closingFor($business, $main, now(), 244000);
        $this->shiftFor($business, $main, User::factory()->create(['business_id' => $business->id]), now()->startOfDay()->addHours(8), 100000)
            ->update(['counted_cash' => 999000]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/cash-shifts/current')
            ->assertJsonPath('expected_opening.amount', 244000)
            ->assertJsonPath('expected_opening.source', 'closing');
    }

    public function test_no_suggestion_while_another_shift_is_still_open(): void
    {
        [$business, $main, $user] = $this->scenario();
        $other = User::factory()->create(['business_id' => $business->id]);
        $this->closingFor($business, $main, now()->subDay(), 200000);
        $this->shiftFor($business, $main, $other, now()->startOfDay()->addHours(8), 200000)
            ->update(['closed_at' => null, 'counted_cash' => null]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/cash-shifts/current')
            ->assertJsonPath('expected_opening', null);
    }

    public function test_the_closing_preview_starts_from_the_accumulated_base(): void
    {
        [$business, $main, $user] = $this->scenario();
        $this->closingFor($business, $main, now()->subDays(3), 100000);
        $this->shiftFor($business, $main, $user, now()->subDays(2)->startOfDay()->addHours(9), 100000)
            ->update(['counted_cash' => 250000]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/cash-closings/preview?date='.now()->toDateString())
            ->assertJsonPath('suggested_opening_cash', 250000)
            ->assertJsonPath('totals.opening_cash', 250000);
    }

    public function test_no_suggestion_without_any_previous_closing(): void
    {
        [, , $user] = $this->scenario();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/cash-shifts/current')
            ->assertJsonPath('expected_opening', null);
    }

    /**
     * Lo que el legacy no tiene: la base es el efectivo de UNA caja. El cierre
     * de la sede de enfrente no puede exigirle una nota a quien abre aca.
     */
    public function test_the_base_left_in_another_branch_does_not_apply(): void
    {
        [$business, $main, $user] = $this->scenario();
        $business->update(['feature_flags' => ['multi_branch' => true]]);
        $second = Branch::factory()->for($business)->create();
        $user->update(['is_business_owner' => true]);

        $this->closingFor($business, $main, now()->subDay(), 200000);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $second->id)
            ->getJson('/api/v1/cash-shifts/current')
            ->assertJsonPath('expected_opening', null);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $second->id)
            ->postJson('/api/v1/cash-shifts', ['opening_cash' => 50000])
            ->assertCreated();
    }

    public function test_the_closing_preview_flags_a_first_shift_opened_with_another_base(): void
    {
        [$business, $main, $user] = $this->scenario();
        $cashier = User::factory()->create(['business_id' => $business->id, 'name' => 'Andres']);
        $this->closingFor($business, $main, now()->subDay(), 200000);
        $this->shiftFor($business, $main, $cashier, now()->startOfDay()->addHours(9)->addMinutes(31), 180000);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/cash-closings/preview?date='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('opening_mismatch.user_name', 'Andres')
            ->assertJsonPath('opening_mismatch.shift_opening_cash', 180000)
            ->assertJsonPath('opening_mismatch.expected_opening_cash', 200000)
            ->assertJsonPath('opening_mismatch.difference', -20000)
            ->assertJsonPath('opening_mismatch.previous_closing_date', now()->subDay()->toDateString());
    }

    public function test_the_closing_preview_stays_quiet_when_the_base_matches(): void
    {
        [$business, $main, $user] = $this->scenario();
        $this->closingFor($business, $main, now()->subDay(), 200000);
        $this->shiftFor($business, $main, $user, now()->startOfDay()->addHours(9), 200000);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/cash-closings/preview?date='.now()->toDateString())
            ->assertJsonPath('opening_mismatch', null);
    }

    /** El cierre del dia anterior registrado tarde, despues de que el turno abrio. */
    public function test_the_closing_preview_tolerates_a_late_previous_closing(): void
    {
        [$business, $main, $user] = $this->scenario();
        $this->shiftFor($business, $main, $user, now()->startOfDay()->addHours(9), 131000);
        $this->closingFor($business, $main, now()->subDay(), 131000);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/cash-closings/preview?date='.now()->toDateString())
            ->assertJsonPath('opening_mismatch', null);
    }
}
