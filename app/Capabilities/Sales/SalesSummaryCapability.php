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
        //
        // Mismos tres filtros que SalesReportService::dailySummary (el
        // "Resumen del dia" que el negocio ve en pantalla), y por la misma
        // razon: si el asistente responde otro numero que la pantalla, el
        // dueño no sabe a cual creerle. Caso real 2026-09-03: la IA dijo
        // $662.900/59 ventas y el Resumen $686.700/60 - la diferencia era
        // exactamente una cortesia sumada de mas y dos cuentas de dias
        // previos cobradas ese dia que faltaban.
        //
        // - closed_at, no created_at: una cuenta abierta el lunes y cobrada
        //   el martes es venta DEL MARTES, que es cuando entro la plata.
        // - is_non_revenue: una cortesia no es venta, se regalo.
        // - is_credit: un fiado entra recien cuando se cobra (ahi es un
        //   Receivable pagado con su medio real).
        $sales = Sale::where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->whereBetween('closed_at', [$start, $end])
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
