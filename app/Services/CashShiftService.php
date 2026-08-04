<?php

namespace App\Services;

use App\Models\CashClosing;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashShiftService
{
    public function __construct(private CashClosingService $cashClosingService) {}

    public function findOpenShiftForUser(User $user): ?CashShift
    {
        return CashShift::query()
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();
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
