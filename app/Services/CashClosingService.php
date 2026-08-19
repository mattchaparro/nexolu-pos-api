<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CashClosing;
use App\Models\CashShift;
use App\Models\Expense;
use App\Models\LayawayPayment;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\ServicePayment;
use App\Models\User;
use App\Support\RevenueByPaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Motor de calculo del cierre de caja: cuanto efectivo deberia haber en la
 * caja de un negocio (cierre diario) o de un cajero (turno). Las "3+1
 * fuentes de ingreso" que se suman en todo cierre son: ventas cerradas que
 * generan ingreso (revenueSales), fiados cobrados (paidReceivables), abonos
 * a servicios (servicePayments) y abonos a apartados (layawayPayments) - un
 * cierre que sume solo ventas subestima el efectivo real que entro.
 */
class CashClosingService
{
    public function calculateTotals(string $date, int $businessId, float $openingCash = 0): array
    {
        // Por closed_at (cuando se cobro), no created_at (cuando se abrio la
        // cuenta): una mesa abierta dias antes y cerrada hoy debe contar en
        // el cierre de HOY, no en el dia en que se abrio.
        $sales = Sale::where('business_id', $businessId)
            ->where('status', 'closed')
            ->whereDate('closed_at', $date)
            ->with('paymentSplits')
            ->get();

        $paidReceivables = Receivable::where('business_id', $businessId)
            ->where('status', 'paid')
            ->whereDate('paid_at', $date)
            ->get();

        $servicePayments = ServicePayment::where('business_id', $businessId)
            ->whereDate('created_at', $date)
            ->get();

        $layawayPayments = LayawayPayment::where('business_id', $businessId)
            ->whereDate('created_at', $date)
            ->get();

        $totalExpenses = (float) Expense::where('business_id', $businessId)
            ->where('scope', 'operacional')
            ->whereDate('date', $date)
            ->sum('value');

        return $this->buildTotals($businessId, $sales, $paidReceivables, $servicePayments, $layawayPayments, $totalExpenses, $openingCash);
    }

    /**
     * Totales de efectivo esperado en un intervalo de tiempo (p. ej. un turno de caja).
     * Los gastos se consideran por fecha contable ('date'), igual que en el cierre
     * diario (calculateTotals): un gasto registrado tarde con fecha de un dia
     * anterior no debe descontarse del turno de hoy, cuya caja no lo perdio.
     *
     * Si se pasa $userId, los totales se atribuyen SOLO a ese cajero (uso: turno
     * individual). Sin $userId, se suma todo el negocio (uso: cierre diario).
     */
    public function calculateTotalsBetween(Carbon $from, Carbon $to, int $businessId, float $openingCash = 0, ?int $userId = null): array
    {
        // Por closed_at Y closed_by_user_id: el efectivo esperado de un turno
        // es lo que ese cajero COBRO en su ventana, no lo que abrio. Filtrar
        // por user_id (quien crea la venta) le sumaria a un cajero el efectivo
        // de una mesa que el abrio pero otro cerro y cobro fisicamente.
        $sales = Sale::where('business_id', $businessId)
            ->where('status', 'closed')
            ->whereBetween('closed_at', [$from, $to])
            ->when($userId, fn ($q) => $q->where('closed_by_user_id', $userId))
            ->with('paymentSplits')
            ->get();

        $paidReceivables = Receivable::where('business_id', $businessId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to])
            ->when($userId, fn ($q) => $q->where('collected_by_user_id', $userId))
            ->get();

        $servicePayments = ServicePayment::where('business_id', $businessId)
            ->whereBetween('created_at', [$from, $to])
            ->when($userId, fn ($q) => $q->where('recorded_by_user_id', $userId))
            ->get();

        $layawayPayments = LayawayPayment::where('business_id', $businessId)
            ->whereBetween('created_at', [$from, $to])
            ->when($userId, fn ($q) => $q->where('recorded_by_user_id', $userId))
            ->get();

        $totalExpenses = (float) Expense::where('business_id', $businessId)
            ->where('scope', 'operacional')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('value');

        return $this->buildTotals($businessId, $sales, $paidReceivables, $servicePayments, $layawayPayments, $totalExpenses, $openingCash);
    }

    /**
     * @return array{
     *   total_sales: float,
     *   total_cash: float,
     *   total_other: float,
     *   opening_cash: float,
     *   total_expenses: float,
     *   expected_cash: float,
     *   payment_breakdown: array<int, array{id: string, label: string, total: float}>,
     * }
     */
    private function buildTotals(int $businessId, Collection $sales, Collection $paidReceivables, Collection $servicePayments, Collection $layawayPayments, float $totalExpenses, float $openingCash): array
    {
        $business = Business::find($businessId);

        $revenueSales = $sales
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->values();

        $servicePaymentsTotal = (float) $servicePayments->sum('amount');
        $layawayPaymentsTotal = (float) $layawayPayments->sum('amount');
        $totalSales = (float) $revenueSales->sum('total')
            + (float) $paidReceivables->sum('amount')
            + $servicePaymentsTotal
            + $layawayPaymentsTotal;

        $breakdown = RevenueByPaymentMethod::combined($business, $revenueSales, $paidReceivables, $servicePayments, $layawayPayments);

        $cashId = $business ? $business->resolveCashPaymentMethodId() : 'cash';
        $totalCash = (float) ($breakdown->firstWhere('id', $cashId)['total'] ?? 0);
        $totalOther = (float) $breakdown
            ->where('id', '!=', $cashId)
            ->sum('total');

        $expectedCash = $openingCash + $totalCash - $totalExpenses;

        return [
            'total_sales' => $totalSales,
            'total_cash' => $totalCash,
            'total_other' => $totalOther,
            'opening_cash' => $openingCash,
            'total_expenses' => $totalExpenses,
            'expected_cash' => $expectedCash,
            'payment_breakdown' => $breakdown->values()->all(),
        ];
    }

    /**
     * Dias anteriores a hoy con actividad de ingresos/turnos que todavia no
     * tienen cierre de caja - la cola que le mostramos al dueño cuando se le
     * acumularon dias sin cerrar, para que los cierre en orden en vez de
     * adivinar que fecha usar. Hoy nunca aparece: el dia todavia esta en
     * curso y cerrarlo es una decision explicita del dueño, no algo atrasado.
     *
     * @return list<string> fechas Y-m-d en orden ascendente
     */
    public function pendingDates(int $businessId, int $maxLookbackDays = 60): array
    {
        $lastClosingDate = CashClosing::where('business_id', $businessId)->max('date');
        $end = now()->subDay()->endOfDay();

        if ($lastClosingDate) {
            $start = Carbon::parse($lastClosingDate)->addDay()->startOfDay();
        } else {
            $earliest = collect([
                Sale::where('business_id', $businessId)->where('status', 'closed')->min('closed_at'),
                Receivable::where('business_id', $businessId)->where('status', 'paid')->min('paid_at'),
                ServicePayment::where('business_id', $businessId)->min('created_at'),
                LayawayPayment::where('business_id', $businessId)->min('created_at'),
                CashShift::where('business_id', $businessId)->min('opened_at'),
            ])->filter()->map(fn ($value) => Carbon::parse($value))->sort()->first();

            if (! $earliest) {
                return [];
            }

            $start = $earliest->startOfDay();
        }

        if ($start->gt($end)) {
            return [];
        }

        // Cota de seguridad: un negocio que nunca ha cerrado caja no debe
        // forzar a iterar años de historial dia por dia.
        $oldestAllowed = now()->subDays($maxLookbackDays)->startOfDay();
        if ($start->lt($oldestAllowed)) {
            $start = $oldestAllowed;
        }

        return collect()
            ->merge($this->distinctDates(Sale::where('business_id', $businessId)->where('status', 'closed'), 'closed_at', $start, $end))
            ->merge($this->distinctDates(Receivable::where('business_id', $businessId)->where('status', 'paid'), 'paid_at', $start, $end))
            ->merge($this->distinctDates(ServicePayment::where('business_id', $businessId), 'created_at', $start, $end))
            ->merge($this->distinctDates(LayawayPayment::where('business_id', $businessId), 'created_at', $start, $end))
            ->merge($this->distinctDates(CashShift::where('business_id', $businessId), 'opened_at', $start, $end))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Builder<*>  $query
     * @return list<string>
     */
    private function distinctDates(Builder $query, string $column, Carbon $start, Carbon $end): array
    {
        return $query
            ->whereBetween($column, [$start, $end])
            ->selectRaw("DISTINCT DATE({$column}) as d")
            ->pluck('d')
            ->all();
    }

    public function closeCash(User $user, array $data): CashClosing
    {
        $businessId = $user->business_id;

        // Evita el 500 crudo por violar cash_closings_business_id_date_unique
        // cuando el formulario se reenvia dos veces (doble clic, reintento tras timeout).
        if (CashClosing::where('business_id', $businessId)->where('date', $data['date'])->exists()) {
            throw ValidationException::withMessages([
                'date' => 'Ya existe un cierre de caja para esta fecha.',
            ]);
        }

        $openingCash = (float) ($data['opening_cash'] ?? 0);
        $totals = $this->calculateTotals($data['date'], $businessId, $openingCash);
        $difference = $data['actual_cash'] - $totals['expected_cash'];

        // Auto-cerrar turnos y crear el cierre deben ser atomicos. Sin esto, si
        // algo falla despues de cerrar el primer turno quedarian turnos
        // cerrados sin ningun cierre de caja que los respalde.
        return DB::transaction(function () use ($user, $businessId, $data, $totals, $difference) {
            try {
                $closing = CashClosing::create([
                    'business_id' => $businessId,
                    'date' => $data['date'],
                    'total_sales' => $totals['total_sales'],
                    'total_cash' => $totals['total_cash'],
                    'total_other_methods' => $totals['total_other'],
                    'payment_breakdown' => $totals['payment_breakdown'],
                    'opening_cash' => $totals['opening_cash'],
                    'total_expenses' => $totals['total_expenses'],
                    'expected_cash' => $totals['expected_cash'],
                    'actual_cash' => $data['actual_cash'],
                    'base_for_next_day' => $data['base_for_next_day'],
                    'difference' => $difference,
                    'closed_by' => $user->id,
                    'created_via' => CashClosing::CREATED_VIA_MANUAL,
                ]);
            } catch (QueryException $e) {
                // Backstop para la carrera real (dos requests casi simultaneas
                // pasando el chequeo de arriba a la vez).
                if ((string) $e->getCode() === '23000') {
                    throw ValidationException::withMessages([
                        'date' => 'Ya existe un cierre de caja para esta fecha.',
                    ]);
                }

                throw $e;
            }

            $this->autoCloseOpenShifts($user, $businessId, $data['date'], $closing);

            return $closing;
        });
    }

    /**
     * Cierra automaticamente los turnos que este cierre de caja "atrapa"
     * (CashShift::scopeAutoClosableOn es la fuente de verdad unica de cuales
     * son - divergir de ese scope aqui anunciaria un auto-cierre que no ocurre).
     */
    private function autoCloseOpenShifts(User $user, int $businessId, string $date, CashClosing $closing): void
    {
        $openShifts = CashShift::where('business_id', $businessId)
            ->autoClosableOn($date)
            ->get();

        if ($openShifts->isEmpty()) {
            return;
        }

        $closeAt = CashShift::closeAtFor($date);

        foreach ($openShifts as $shift) {
            $totals = $this->calculateTotalsBetween(
                $shift->opened_at->copy(),
                $closeAt,
                $businessId,
                (float) $shift->opening_cash,
                (int) $shift->user_id
            );

            $expected = (float) $totals['expected_cash'];

            $shift->update([
                'closed_at' => $closeAt,
                'counted_cash' => $expected,
                'expected_cash' => $expected,
                'difference' => 0,
                'closing_note' => "Cerrado automaticamente al realizar el cierre de caja del {$date}",
                'closed_by_user_id' => $user->id,
                'closed_via' => CashShift::CLOSED_VIA_AUTO_CASH_CLOSING,
                'closed_by_cash_closing_id' => $closing->id,
                'payment_breakdown' => $totals['payment_breakdown'],
                'total_sales' => $totals['total_sales'],
                'total_cash' => $totals['total_cash'],
                'total_other_methods' => $totals['total_other'],
                'total_expenses' => $totals['total_expenses'],
            ]);
        }
    }

    public function updateCashClosing(CashClosing $closing, array $data): CashClosing
    {
        $openingCash = (float) ($data['opening_cash'] ?? 0);
        $totals = $this->calculateTotals($closing->date->format('Y-m-d'), (int) $closing->business_id, $openingCash);

        $closing->update([
            'total_sales' => $totals['total_sales'],
            'total_cash' => $totals['total_cash'],
            'total_other_methods' => $totals['total_other'],
            'payment_breakdown' => $totals['payment_breakdown'],
            'opening_cash' => $totals['opening_cash'],
            'total_expenses' => $totals['total_expenses'],
            'expected_cash' => $totals['expected_cash'],
            'actual_cash' => $data['actual_cash'],
            'base_for_next_day' => $data['base_for_next_day'],
            'difference' => $data['actual_cash'] - $totals['expected_cash'],
        ]);

        return $closing->fresh('closedByUser');
    }

    /**
     * Deshace un cierre de caja hecho por error. Reabre los turnos que ESTE
     * cierre auto-cerro (no toca turnos cerrados manualmente antes del error)
     * y borra el registro del cierre.
     *
     * Restringido al MISMO dia: un cierre de ayer o antes ya pudo quedar
     * reflejado en otros reportes/decisiones (p. ej. base_for_next_day ya
     * usada al dia siguiente); deshacerlo fuera del mismo dia es mas
     * arriesgado que el problema que resuelve.
     */
    public function undoCashClosing(CashClosing $closing): void
    {
        if ($closing->date->toDateString() !== now()->toDateString()) {
            throw ValidationException::withMessages([
                'date' => 'Solo se puede deshacer el cierre del mismo día en que se hizo.',
            ]);
        }

        DB::transaction(function () use ($closing) {
            CashShift::where('closed_by_cash_closing_id', $closing->id)->update([
                'closed_at' => null,
                'counted_cash' => null,
                'expected_cash' => null,
                'difference' => null,
                'closing_note' => null,
                'payment_breakdown' => null,
                'total_sales' => null,
                'total_cash' => null,
                'total_other_methods' => null,
                'total_expenses' => null,
                'closed_by_user_id' => null,
                'closed_via' => null,
                'closed_by_cash_closing_id' => null,
            ]);

            $closing->delete();
        });
    }
}
