<?php

namespace App\Capabilities\Cash;

use App\Capabilities\Capability;
use App\Models\Business;
use App\Models\User;
use App\Services\CashClosingService;
use App\Services\CashShiftService;

/**
 * Tool: estado_caja. Mismo calculo que CashShiftController::current(): el
 * turno abierto del usuario y un avance de sus totales hasta ahora.
 */
class CashStatusCapability implements Capability
{
    public function __construct(
        private CashShiftService $cashShiftService,
        private CashClosingService $cashClosingService,
    ) {}

    public function requiredPermission(): ?string
    {
        return 'cash_shift.manage';
    }

    public function requiredFeature(): ?string
    {
        return null;
    }

    public function rules(): array
    {
        return [];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $shift = $this->cashShiftService->findOpenShiftForUser($user);

        if (! $shift) {
            return ['caja_abierta' => false];
        }

        $totals = $this->cashClosingService->calculateTotalsBetween(
            $shift->opened_at->copy(),
            now(),
            (int) $business->id,
            (float) $shift->opening_cash,
            (int) $user->id
        );

        return [
            'caja_abierta' => true,
            'abierta_desde' => $shift->opened_at->toIso8601String(),
            'efectivo_apertura' => (float) $shift->opening_cash,
            'saldo_esperado' => round((float) $totals['expected_cash'], 2),
            'total_vendido_turno' => round((float) $totals['total_sales'], 2),
        ];
    }
}
