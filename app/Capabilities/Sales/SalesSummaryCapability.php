<?php

namespace App\Capabilities\Sales;

use App\Capabilities\Capability;
use App\Capabilities\Support\ResolvesDateRange;
use App\Models\Business;
use App\Models\Sale;
use App\Models\User;

/** Tool: ventas_resumen. Resumen de ventas del negocio en un rango de fechas. */
class SalesSummaryCapability implements Capability
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
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        [$start, $end] = $this->resolveDateRange($arguments['desde'] ?? null, $arguments['hasta'] ?? null);

        // La consulta se scopea sola al negocio via el global scope de
        // BelongsToBusiness, que lee auth()->user() - ya seteado por el
        // dispatcher antes de llegar aca.
        $sales = Sale::where('status', 'closed')
            ->whereBetween('created_at', [$start, $end])
            ->get(['total']);

        $count = $sales->count();
        $total = (float) $sales->sum('total');

        return [
            'desde' => $start->toDateString(),
            'hasta' => $end->toDateString(),
            'numero_ventas' => $count,
            'total_vendido' => round($total, 2),
            'ticket_promedio' => $count > 0 ? round($total / $count, 2) : 0.0,
        ];
    }
}
