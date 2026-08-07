<?php

namespace App\Capabilities\Support;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Mismas guardas que ToolGuard.resolve_date_range() del lado del IA Core
 * (ver core/tools/guard.py en Nexolu-IA-Core): un rango es una regla de
 * negocio y este API es quien la aplica de verdad, no un adorno duplicado.
 */
trait ResolvesDateRange
{
    private const MAX_RANGE_DAYS = 366;

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveDateRange(?string $desde, ?string $hasta): array
    {
        $start = $desde ? Carbon::createFromFormat('Y-m-d', $desde)->startOfDay() : now()->subDays(29)->startOfDay();
        $end = $hasta ? Carbon::createFromFormat('Y-m-d', $hasta)->endOfDay() : now()->endOfDay();

        if ($start->gt($end)) {
            throw ValidationException::withMessages(['desde' => "La fecha inicial ({$desde}) es posterior a la final ({$hasta})."]);
        }

        if ($start->diffInDays($end) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['desde' => 'El rango no puede superar '.self::MAX_RANGE_DAYS.' dias.']);
        }

        if ($start->isFuture()) {
            throw ValidationException::withMessages(['desde' => 'El rango consultado esta en el futuro; no hay datos.']);
        }

        return [$start, $end];
    }
}
