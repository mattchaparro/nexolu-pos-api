<?php

namespace App\Services;

use App\Models\CashClosing;
use App\Models\CashShift;
use App\Models\User;
use App\Support\BranchContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashShiftService
{
    public function __construct(
        private CashClosingService $cashClosingService,
        private BranchService $branchService,
    ) {}

    public function findOpenShiftForUser(User $user): ?CashShift
    {
        return CashShift::query()
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();
    }

    /**
     * La sede cuya caja recibe un turno abierto ahora: la del request, o la
     * principal. Replica a proposito lo que BelongsToBranch estampa al crear
     * el turno, para que la base esperada se busque en la misma caja donde
     * el turno va a quedar.
     */
    public function drawerBranchId(int $businessId): ?int
    {
        return BranchContext::branchId() ?? $this->branchService->mainBranchId($businessId);
    }

    /**
     * Cuanto efectivo DEBERIA haber en la caja de $branchId al abrir un turno
     * en $at, o null si no hay forma de saberlo.
     *
     * Es lo que dejo el ultimo turno cerrado (el efectivo que conto el cajero)
     * o, si no hubo turnos desde el ultimo cierre de caja, la
     * `base_for_next_day` de ese cierre. Asi la base se acumula dia tras dia
     * sin que el dueño tenga que cerrar caja a diario. Si hay un turno
     * abierto en esa caja, null: el efectivo se esta moviendo.
     *
     * Origen: un descuadre real en el POS legacy (Central Cell, 17-sep-2026).
     * El cierre del 16 dejo $200.000 de base, el turno del 17 abrio declarando
     * $180.000, y como la apertura no sugeria nada el turno cerro en $0 y los
     * $20.000 aparecieron un dia despues en el cierre de caja, donde parecian
     * un problema de ese dia. Aca la apertura ademas arrancaba en $0, que es
     * peor: invita a abrir sin mirar.
     *
     * Por sede y no por negocio: la base es el efectivo de UNA caja. La base
     * que dejo el cierre de la sede de enfrente no dice nada de esta.
     * Filtra la sede de forma explicita (sin el scope global) porque en el
     * modo "todas las sedes" el scope no filtra, y en un job no hay contexto.
     *
     * @return array{amount: float, closing_date: Carbon, source: 'closing'|'shift'}|null
     */
    public function expectedOpeningCash(int $businessId, ?int $branchId, ?CarbonInterface $at = null): ?array
    {
        if ($branchId === null) {
            return null;
        }

        $at ??= now();

        $lastClosing = CashClosing::withoutGlobalScope('branch')
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->whereDate('date', '<', $at->toDateString())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        $closingDate = $lastClosing ? Carbon::parse($lastClosing->date) : null;

        // El turno mas reciente DESPUES de lo que cubre el ultimo cierre (por
        // el dia que cubre, no por su created_at: un cierre atrasado no debe
        // volver "primero" a un turno que ya estaba corriendo).
        $lastShift = CashShift::withoutGlobalScope('branch')
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->when($closingDate, fn ($query) => $query->where('opened_at', '>=', $closingDate->copy()->addDay()->startOfDay()))
            ->where('opened_at', '<', $at)
            ->orderByDesc('opened_at')
            ->first();

        if ($lastShift) {
            // La base se va acumulando de turno en turno hasta que el dueño
            // cierre caja: lo que conto el ultimo cajero es lo que queda.
            // Si ese turno sigue abierto, la caja se esta moviendo ahora.
            if ($lastShift->closed_at === null || $lastShift->counted_cash === null) {
                return null;
            }

            return [
                'amount' => (float) $lastShift->counted_cash,
                'closing_date' => Carbon::parse($lastShift->closed_at),
                'source' => 'shift',
            ];
        }

        if (! $lastClosing) {
            return null;
        }

        return [
            'amount' => (float) $lastClosing->base_for_next_day,
            'closing_date' => $closingDate,
            'source' => 'closing',
        ];
    }

    /**
     * Si el primer turno de $date en $branchId abrio con una base distinta a
     * la que dejo el cierre anterior, los datos de esa diferencia; si no, null.
     *
     * La vista previa del cierre mostraba la base sugerida pero nunca la
     * comparaba con la base con la que abrio el dia. El admin cerraba sin
     * saber que el faltante venia de antes de empezar a vender.
     *
     * @return array{user_name: string, shift_opening_cash: float, expected_opening_cash: float, difference: float, previous_closing_date: string}|null
     */
    public function openingMismatchOn(string $date, int $businessId, ?int $branchId): ?array
    {
        if ($branchId === null) {
            return null;
        }

        $firstShift = CashShift::withoutGlobalScope('branch')
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->whereDate('opened_at', $date)
            ->with('user:id,name,last_name')
            ->orderBy('opened_at')
            ->first();

        if (! $firstShift) {
            return null;
        }

        $expected = $this->expectedOpeningCash($businessId, $branchId, $firstShift->opened_at);

        if ($expected === null) {
            return null;
        }

        $difference = round((float) $firstShift->opening_cash - $expected['amount'], 2);

        if (abs($difference) < 0.01) {
            return null;
        }

        return [
            'user_name' => $firstShift->user?->fullName() ?? '-',
            'shift_opening_cash' => (float) $firstShift->opening_cash,
            'expected_opening_cash' => $expected['amount'],
            'difference' => $difference,
            'previous_closing_date' => $expected['closing_date']->toDateString(),
        ];
    }

    public function openShift(User $user, float $openingCash, ?string $openingNote): CashShift
    {
        return DB::transaction(function () use ($user, $openingCash, $openingNote) {
            $locked = CashShift::query()
                ->where('user_id', $user->id)
                ->whereNull('closed_at')
                ->lockForUpdate()
                ->first();

            if ($locked) {
                throw ValidationException::withMessages(['shift' => 'Ya tienes un turno abierto.']);
            }

            $hasDayClosing = CashClosing::where('business_id', $user->business_id)
                ->where('date', now()->toDateString())
                ->exists();

            if ($hasDayClosing) {
                throw ValidationException::withMessages(['shift' => 'Ya existe un cierre de caja para hoy. No se pueden abrir nuevos turnos.']);
            }

            return CashShift::create([
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'opened_at' => now(),
                'opening_cash' => $openingCash,
                'opening_note' => $openingNote,
            ]);
        });
    }

    /** Solo quien abrio el turno puede cerrarlo - el efectivo que cuenta es el que ESE cajero tiene en caja. */
    public function closeShift(User $user, CashShift $shift, float $countedCash, ?string $closingNote): CashShift
    {
        if ((int) $shift->user_id !== (int) $user->id) {
            throw ValidationException::withMessages(['shift' => 'No puedes cerrar el turno de otro usuario.']);
        }

        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'Este turno ya está cerrado.']);
        }

        $end = now();
        $totals = $this->cashClosingService->calculateTotalsBetween(
            $shift->opened_at->copy(),
            $end,
            (int) $shift->business_id,
            (float) $shift->opening_cash,
            (int) $shift->user_id
        );

        $expected = (float) $totals['expected_cash'];

        $shift->update([
            'closed_at' => $end,
            'counted_cash' => $countedCash,
            'expected_cash' => $expected,
            'difference' => $countedCash - $expected,
            'closing_note' => $closingNote,
            'payment_breakdown' => $totals['payment_breakdown'],
            'total_sales' => $totals['total_sales'],
            'total_cash' => $totals['total_cash'],
            'total_other_methods' => $totals['total_other'],
            'total_expenses' => $totals['total_expenses'],
            'closed_by_user_id' => $user->id,
            'closed_via' => CashShift::CLOSED_VIA_MANUAL,
        ]);

        return $shift->fresh();
    }
}
