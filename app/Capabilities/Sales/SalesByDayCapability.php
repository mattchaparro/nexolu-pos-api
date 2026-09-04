<?php

namespace App\Capabilities\Sales;

use App\Capabilities\Capability;
use App\Capabilities\Support\ResolvesDateRange;
use App\Models\Business;
use App\Models\Sale;
use App\Models\User;

/** Tool: ventas_por_dia. Serie diaria de ventas dentro de un rango de fechas. */
class SalesByDayCapability implements Capability
{
    use ResolvesDateRange;

    public function requiredPermission(): ?string
    {
        return 'reports.sales';
    }

    public function requiredFeature(): ?string
    {
        return null;
    }

    public function rules(): array
    {
        return [
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        [$start, $end] = $this->resolveDateRange($arguments['desde'], $arguments['hasta']);

        // closed_at + solo ventas de ingreso, igual que SalesSummaryCapability
        // y que el "Resumen del dia" en pantalla - ver el docblock de
        // SalesSummaryCapability::execute() para el porque (y el caso real
        // que lo destapo). Agrupar por DATE(created_at) ademas partia una
        // cuenta abierta el lunes y cobrada el martes en el dia equivocado.
        $rows = Sale::where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->whereBetween('closed_at', [$start, $end])
            ->selectRaw('DATE(closed_at) as fecha, COUNT(*) as numero_ventas, SUM(total) as total_vendido')
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get();

        return [
            'desde' => $start->toDateString(),
            'hasta' => $end->toDateString(),
            'dias' => $rows->map(fn ($row) => [
                'fecha' => $row->fecha,
                'numero_ventas' => (int) $row->numero_ventas,
                'total_vendido' => round((float) $row->total_vendido, 2),
            ])->all(),
        ];
    }
}
