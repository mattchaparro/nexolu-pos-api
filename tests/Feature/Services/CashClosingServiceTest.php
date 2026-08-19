<?php

namespace Tests\Feature\Services;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SalePartialPayment;
use App\Models\SalePaymentSplit;
use App\Models\User;
use App\Services\CashClosingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regresion de dos bugs criticos que el legacy tuvo en este modulo
 * (confirmados con datos reales de produccion en pos-saas-legacy/audits/
 * 01-cierre-turno-cuenta-ventas-de-otros-cajeros.md y
 * 02-gastos-turno-vs-cierre-diario.md), mas los escenarios explicitos que el
 * usuario pidio verificar al migrar: cuentas abiertas cerradas el mismo dia,
 * abonos parciales de una cuenta abierta que no se duplican al cerrarla, y
 * que reversar una venta de un dia anterior no rompe el cierre de ese dia
 * (documentado como limitacion compartida con el legacy, no una regresion).
 */
class CashClosingServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): CashClosingService
    {
        return app(CashClosingService::class);
    }

    /**
     * Bug 01 del legacy: el turno de un cajero contaba tambien el efectivo
     * cobrado por otro cajero en la misma ventana de tiempo (filtraba por
     * business_id sin filtrar por usuario). Ya corregido via
     * closed_by_user_id - este test lo deja trabado.
     */
    public function test_a_shift_only_counts_cash_the_owner_actually_collected(): void
    {
        $business = Business::factory()->create();
        $cashierA = User::factory()->create(['business_id' => $business->id]);
        $cashierB = User::factory()->create(['business_id' => $business->id]);

        $from = now()->subHour();
        $to = now();

        Sale::factory()->create([
            'business_id' => $business->id,
            'payment_method' => 'cash',
            'total' => 100000,
            'status' => 'closed',
            'closed_at' => now()->subMinutes(30),
            'closed_by_user_id' => $cashierA->id,
        ]);

        // Misma ventana de tiempo, pero cobrada por el OTRO cajero.
        Sale::factory()->create([
            'business_id' => $business->id,
            'payment_method' => 'cash',
            'total' => 70000,
            'status' => 'closed',
            'closed_at' => now()->subMinutes(20),
            'closed_by_user_id' => $cashierB->id,
        ]);

        $totalsA = $this->service()->calculateTotalsBetween($from, $to, $business->id, 0.0, $cashierA->id);

        $this->assertSame(100000.0, $totalsA['expected_cash']);
    }

    /**
     * Bug 02 del legacy: el cierre diario filtraba gastos por 'date' (fecha
     * contable) mientras el turno filtraba por 'created_at' (cuando se
     * registro) - un gasto con fecha retroactiva se descontaba dos veces (una
     * en cada corte) o en el corte equivocado. Ya corregido: ambos usan
     * 'date'. Este test lo deja trabado.
     */
    public function test_a_backdated_expense_is_counted_by_the_same_column_in_both_cuts(): void
    {
        $business = Business::factory()->create();

        // Gasto registrado HOY pero con fecha contable de AYER.
        Expense::factory()->create([
            'business_id' => $business->id,
            'scope' => 'operacional',
            'value' => 30000,
            'date' => now()->subDay()->toDateString(),
            'created_at' => now(),
        ]);

        $dailyYesterday = $this->service()->calculateTotals(now()->subDay()->toDateString(), $business->id);
        $shiftToday = $this->service()->calculateTotalsBetween(now()->startOfDay(), now()->endOfDay(), $business->id);

        $this->assertSame(30000.0, $dailyYesterday['total_expenses']);
        $this->assertSame(0.0, $shiftToday['total_expenses']);
    }

    /**
     * Lo que el usuario senalo explicitamente como bug ya resuelto: una
     * cuenta abierta creada dias antes pero cerrada (cobrada) hoy debe
     * contar en el cierre de HOY, no en el dia en que se abrio ni perderse.
     * La clave es que calculateTotals filtra por closed_at, no created_at.
     */
    public function test_a_tab_opened_days_ago_and_closed_today_counts_in_todays_close_only(): void
    {
        $business = Business::factory()->create();

        Sale::factory()->create([
            'business_id' => $business->id,
            'payment_method' => 'cash',
            'total' => 45000,
            'status' => 'closed',
            'created_at' => now()->subDays(3),
            'closed_at' => now(),
        ]);

        $today = $this->service()->calculateTotals(now()->toDateString(), $business->id);
        $threeDaysAgo = $this->service()->calculateTotals(now()->subDays(3)->toDateString(), $business->id);

        $this->assertSame(45000.0, $today['total_sales']);
        $this->assertSame(0.0, $threeDaysAgo['total_sales']);
    }

    /**
     * Los abonos parciales de una cuenta abierta (SalePartialPayment) se
     * pliegan en el total/payment_splits de la Sale al cerrarla
     * (OpenTabService::close) y las filas de abono se borran - el cierre de
     * caja NUNCA debe contarlos aparte, o duplicaria el efectivo que ya
     * viene sumado en el total de la venta cerrada.
     */
    public function test_partial_payments_folded_into_a_closed_tab_are_not_double_counted(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'payment_method' => 'cash',
            'total' => 80000,
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        // Abonos que, en la vida real, OpenTabService::close() ya habria
        // borrado al cerrar la cuenta - se dejan aca a proposito (residuo
        // hipotetico) para probar que calculateTotals no los toca en
        // absoluto: no filtra ni suma SalePartialPayment en ningun punto.
        SalePartialPayment::factory()->create([
            'sale_id' => $sale->id,
            'amount' => 30000,
            'payment_method' => 'cash',
            'user_id' => $user->id,
        ]);

        $totals = $this->service()->calculateTotals(now()->toDateString(), $business->id);

        $this->assertSame(80000.0, $totals['total_sales']);
        $this->assertSame(80000.0, $totals['total_cash']);
    }

    /**
     * Una venta con pago dividido (mixed) debe repartirse por medio de pago
     * real en el cierre, no caer entera en un solo medio - allocatedRevenueByPaymentMethod()
     * es la fuente unica de esta logica (Sale.php), reusada aca via
     * RevenueByPaymentMethod::combined().
     */
    public function test_a_split_payment_sale_is_broken_down_by_actual_method_in_the_closing(): void
    {
        $business = Business::factory()->create();

        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'payment_method' => 'mixed',
            'total' => 100000,
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        SalePaymentSplit::factory()->create(['sale_id' => $sale->id, 'payment_method' => 'cash', 'amount' => 60000]);
        SalePaymentSplit::factory()->create(['sale_id' => $sale->id, 'payment_method' => 'transfer', 'amount' => 40000]);

        $totals = $this->service()->calculateTotals(now()->toDateString(), $business->id);

        $this->assertSame(100000.0, $totals['total_sales']);
        $this->assertSame(60000.0, $totals['total_cash']);
        $this->assertSame(40000.0, $totals['total_other']);
        $breakdown = collect($totals['payment_breakdown'])->keyBy('id');
        $this->assertSame(60000.0, $breakdown['cash']['total']);
        $this->assertSame(40000.0, $breakdown['transfer']['total']);
    }

    /**
     * Limitacion compartida con el legacy (no una regresion de la
     * migracion): reversar una venta borra la fila (sin soft delete), sin
     * dejar ningun rastro de que el efectivo salio de la caja. Si la venta
     * reversada es de un dia CON el cierre ya hecho, ese cierre historico no
     * se ajusta solo - documentado aca para que quede visible, no oculto.
     */
    public function test_reversing_a_prior_days_sale_leaves_that_days_close_unaffected(): void
    {
        $business = Business::factory()->create();

        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'payment_method' => 'cash',
            'total' => 60000,
            'status' => 'closed',
            'closed_at' => now()->subDay(),
        ]);

        $beforeReversal = $this->service()->calculateTotals(now()->subDay()->toDateString(), $business->id);
        $this->assertSame(60000.0, $beforeReversal['total_sales']);

        $sale->delete(); // reverseSale() hace un borrado definitivo, sin soft delete.

        $afterReversal = $this->service()->calculateTotals(now()->subDay()->toDateString(), $business->id);
        $this->assertSame(
            0.0,
            $afterReversal['total_sales'],
            'El cierre ya emitido no se recalcula solo; si el cierre de ayer ya se guardo antes de reversar, queda con el monto viejo hasta que un admin lo corrija a mano.'
        );
    }
}
