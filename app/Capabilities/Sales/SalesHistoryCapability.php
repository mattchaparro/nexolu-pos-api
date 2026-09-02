<?php

namespace App\Capabilities\Sales;

use App\Capabilities\Capability;
use App\Models\Business;
use App\Models\Sale;
use App\Models\User;

/**
 * Tool: ventas_historico. Todo lo vendido desde que el negocio usa el sistema,
 * mes a mes.
 *
 * Existe aparte de ventas_resumen porque "cuanto he vendido desde que tengo el
 * programa" no tiene fecha de inicio: obligar al modelo a inventarse un
 * "desde" era la unica alternativa, y se equivocaba.
 */
class SalesHistoryCapability implements Capability
{
    /** El historial completo se resume; solo se listan los meses recientes. */
    private const MAX_MONTHS = 36;

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
        return [];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        // Mismos criterios de "venta que cuenta como ingreso" que el resto de
        // los reportes, para que el chat no contradiga al tablero.
        $base = fn () => Sale::query()
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false);

        $rows = $base()
            // Por closed_at: una cuenta abierta que cruza de mes cuenta en el
            // mes en que se cobro, no en el que se abrio.
            ->selectRaw("DATE_FORMAT(closed_at, '%Y-%m') as month")
            ->selectRaw('SUM(total) as total')
            ->selectRaw('COUNT(*) as sales')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'tiene_ventas' => false,
                'mensaje' => 'Este negocio todavia no tiene ventas registradas.',
            ];
        }

        $months = $rows->map(fn ($row) => [
            'mes' => (string) $row->month,
            'total' => round((float) $row->total, 2),
            'ventas' => (int) $row->sales,
        ])->values();

        $total = round($months->sum('total'), 2);
        $monthsWithSales = $months->count();
        $firstSale = $base()->min('created_at');

        return [
            'tiene_ventas' => true,
            'primera_venta' => $firstSale ? substr((string) $firstSale, 0, 10) : null,
            'meses_con_ventas' => $monthsWithSales,
            'total_vendido' => $total,
            // El promedio va sobre los meses CON ventas, no sobre el
            // calendario: un negocio que estuvo cerrado dos meses no deberia
            // ver su promedio diluido por meses en los que no abrio.
            'promedio_mensual' => $monthsWithSales > 0 ? round($total / $monthsWithSales, 2) : 0,
            'mejor_mes' => $months->sortByDesc('total')->first(),
            // Si el historial excede el tope se recortan los meses MAS VIEJOS:
            // el total y el promedio ya van calculados sobre todo, y lo
            // reciente es lo que se suele mirar.
            'por_mes' => $months->slice(-self::MAX_MONTHS)->values()->all(),
            'nota_promedio' => 'El promedio mensual se calcula sobre los meses que tuvieron ventas, '
                .'no sobre todos los meses del calendario.',
        ];
    }
}
