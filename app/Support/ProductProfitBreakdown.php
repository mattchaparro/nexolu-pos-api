<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Utilidad bruta por producto vendido en un periodo. El costo usado es el
 * que tenia el producto EN EL MOMENTO de cada venta (sale_items.unit_cost_at_sale,
 * congelado al vender), no el costo actual: el costo promedio se mueve con
 * cada compra, y recalcular con el costo de hoy haria que la utilidad de un
 * mes ya cerrado cambiara sola cada vez que entra una compra nueva. Para
 * ventas de antes de que existiera esa columna, cae a products.cost_price
 * via COALESCE.
 *
 * Productos cuyo costo efectivo en el periodo fue $0 no entran a 'lines':
 * asumir costo 0 les daria un margen falso del 100%. Van aparte en
 * 'uncosted', solo con el ingreso, sin inventar una utilidad.
 *
 * Extraida de ManagerialAccountingService::productProfitLines() para que
 * BusinessOverviewService (Mi negocio) la reuse sin reimplementar la misma
 * consulta - antes de esta clase la logica solo vivia ahi.
 */
class ProductProfitBreakdown
{
    /**
     * @return array{
     *     lines: list<array{product_id: int, name: string, sku: string|null, qty_sold: int, revenue: float, cost_total: float, profit: float, margin_pct: float|null}>,
     *     total_profit: float, total_revenue: float, total_cost: float, margin_pct: float|null,
     *     uncosted: array{lines: list<array<string, mixed>>, total_revenue: float, products_count: int},
     * }
     */
    public static function forPeriod(int $businessId, Carbon $start, Carbon $end): array
    {
        $rows = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->select([
                'p.id',
                'p.name',
                'p.sku',
                DB::raw('SUM(si.quantity) as qty_sold'),
                DB::raw('SUM(si.subtotal - COALESCE(si.discount_amount, 0)) as revenue'),
                DB::raw('SUM(si.quantity * COALESCE(si.unit_cost_at_sale, p.cost_price, 0)) as cost_total'),
                DB::raw('SUM(si.subtotal - COALESCE(si.discount_amount, 0) - si.quantity * COALESCE(si.unit_cost_at_sale, p.cost_price, 0)) as profit'),
            ])
            ->where('s.business_id', $businessId)
            ->where('s.status', 'closed')
            ->where('s.is_non_revenue', false)
            // Fiado (is_credit) queda fuera igual que en el resto del reporte
            // (revenueTotals(), productRotation()): el ingreso de esa venta
            // todavia no se reconoce, solo cuando el fiado se cobra - contar
            // su utilidad ahora inflaria el margen del mes sin plata real
            // detras.
            ->where('s.is_credit', false)
            ->whereBetween('s.closed_at', [$start, $end])
            ->groupBy('p.id', 'p.name', 'p.sku')
            ->get();

        $costedRows = $rows->filter(fn ($row) => (float) $row->cost_total > 0.009)->sortByDesc('profit')->values();
        $uncostedRows = $rows->filter(fn ($row) => (float) $row->cost_total <= 0.009)->sortByDesc('revenue')->values();

        $totalRevenue = round((float) $costedRows->sum('revenue'), 2);
        $totalCost = round((float) $costedRows->sum('cost_total'), 2);
        $totalProfit = round((float) $costedRows->sum('profit'), 2);

        return [
            'lines' => $costedRows->map(fn ($row) => self::line($row))->all(),
            'total_profit' => $totalProfit,
            'total_revenue' => $totalRevenue,
            'total_cost' => $totalCost,
            'margin_pct' => $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 1) : null,
            'uncosted' => [
                'lines' => $uncostedRows->map(fn ($row) => [
                    'name' => $row->name,
                    'qty_sold' => (int) $row->qty_sold,
                    'revenue' => round((float) $row->revenue, 2),
                ])->all(),
                'total_revenue' => round((float) $uncostedRows->sum('revenue'), 2),
                'products_count' => $uncostedRows->count(),
            ],
        ];
    }

    /** @return array{product_id: int, name: string, sku: string|null, qty_sold: int, revenue: float, cost_total: float, profit: float, margin_pct: float|null} */
    private static function line(object $row): array
    {
        $revenue = round((float) $row->revenue, 2);
        $profit = round((float) $row->profit, 2);

        return [
            'product_id' => (int) $row->id,
            'name' => (string) $row->name,
            'sku' => $row->sku,
            'qty_sold' => (int) $row->qty_sold,
            'revenue' => $revenue,
            'cost_total' => round((float) $row->cost_total, 2),
            'profit' => $profit,
            'margin_pct' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : null,
        ];
    }
}
