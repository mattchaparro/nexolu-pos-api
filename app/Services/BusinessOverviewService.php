<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Expense;
use App\Models\LayawayPayment;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServicePayment;
use App\Support\ProductProfitBreakdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Mi negocio": un reporte de reportes, pensado para que el dueño entienda de
 * un vistazo como le va - no una repeticion de Resumen del dia. Tres bloques:
 *
 * - pulse: hoy vs ayer, esta semana vs la anterior (siempre relativo a AHORA,
 *   sin importar que mes se este mirando en `period`).
 * - trend/heatmap: patron de los ultimos 30 dias reales - la tendencia diaria
 *   y las horas calientes/frias (por venta directa cerrada; los cobros de
 *   fiados/servicios no reflejan cuando entra gente a comprar, asi que quedan
 *   fuera de estas dos vistas especificas, no del resto del reporte).
 * - period: el mes elegido (year/month) - resumen, descuentos entregados,
 *   fiado pendiente, rotacion de productos/servicios con su impacto en el
 *   ingreso del mes, y margen de ganancia (solo si el usuario tiene
 *   accounting.manage - expone rentabilidad real, no solo reportes de venta).
 */
class BusinessOverviewService
{
    public function overview(Business $business, int $year, int $month, bool $canViewProfit = false): array
    {
        return [
            'pulse' => $this->pulse($business),
            'trend' => $this->trend($business),
            'heatmap' => $this->heatmap($business),
            'period' => $this->period($business, $year, $month, $canViewProfit),
            'channels_enabled' => [
                'services' => $business->hasFeature('services'),
                'receivables' => $business->hasFeature('receivables'),
                'layaway' => $business->hasFeature('layaway'),
                'expenses' => $business->hasFeature('expenses'),
            ],
        ];
    }

    /**
     * @return array{
     *   today: array{revenue: float, sales_count: int, avg_ticket: float},
     *   yesterday: array{revenue: float, sales_count: int, avg_ticket: float},
     *   today_vs_yesterday_pct: float|null,
     *   this_week: array{revenue: float, sales_count: int, avg_ticket: float},
     *   last_week: array{revenue: float, sales_count: int, avg_ticket: float},
     *   week_vs_last_week_pct: float|null,
     * }
     */
    private function pulse(Business $business): array
    {
        $businessId = $business->id;
        $now = Carbon::now();

        $today = $this->revenueTotals($businessId, $now->copy()->startOfDay(), $now->copy()->endOfDay());
        $yesterday = $this->revenueTotals($businessId, $now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay());
        $thisWeek = $this->revenueTotals($businessId, $now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay());
        $lastWeek = $this->revenueTotals($businessId, $now->copy()->subDays(13)->startOfDay(), $now->copy()->subDays(7)->endOfDay());

        return [
            'today' => $today,
            'yesterday' => $yesterday,
            'today_vs_yesterday_pct' => $this->growthPct($today['revenue'], $yesterday['revenue']),
            'this_week' => $thisWeek,
            'last_week' => $lastWeek,
            'week_vs_last_week_pct' => $this->growthPct($thisWeek['revenue'], $lastWeek['revenue']),
        ];
    }

    /**
     * Ingreso "3+1 fuentes" (mismo criterio que CashClosingService/dailySummary:
     * ventas cerradas + fiados cobrados + abonos a servicios + abonos a
     * apartados) de una ventana - version liviana sin desglose por medio de
     * pago, que aca no hace falta.
     *
     * @return array{revenue: float, sales_count: int, avg_ticket: float}
     */
    private function revenueTotals(int $businessId, Carbon $from, Carbon $to): array
    {
        $revenueSales = Sale::where('business_id', $businessId)
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->whereBetween('closed_at', [$from, $to]);

        $salesRevenue = (float) (clone $revenueSales)->sum('total');
        $salesCount = (clone $revenueSales)->count();

        $receivablesCollected = (float) Receivable::where('business_id', $businessId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');

        $servicePaymentsCollected = (float) ServicePayment::where('business_id', $businessId)
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        $layawayPaymentsCollected = (float) LayawayPayment::where('business_id', $businessId)
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        $totalRevenue = $salesRevenue + $receivablesCollected + $servicePaymentsCollected + $layawayPaymentsCollected;

        return [
            'revenue' => $totalRevenue,
            'sales_count' => $salesCount,
            'avg_ticket' => $salesCount > 0 ? round($salesRevenue / $salesCount, 0) : 0.0,
        ];
    }

    /**
     * @return array{days: list<array{date: string, revenue: float, sales_count: int}>}
     */
    private function trend(Business $business, int $days = 30): array
    {
        $from = Carbon::now()->subDays($days - 1)->startOfDay();
        $to = Carbon::now()->endOfDay();

        $rows = Sale::where('business_id', $business->id)
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->whereBetween('closed_at', [$from, $to])
            ->selectRaw('DATE(closed_at) as day')
            ->selectRaw('SUM(total) as revenue')
            ->selectRaw('COUNT(*) as sales_count')
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $result = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);
            $result[] = [
                'date' => $key,
                'revenue' => $row ? (float) $row->revenue : 0.0,
                'sales_count' => $row ? (int) $row->sales_count : 0,
            ];
        }

        return ['days' => $result];
    }

    /**
     * Horas calientes/frias: ingreso de ventas directas cerradas por dia de
     * semana x hora, en los ultimos $days dias. dayofweek 0=domingo..6=sabado
     * (Carbon::dayOfWeek), igual que DAYOFWEEK()-1 de MySQL - misma
     * convencion en toda la respuesta para que el frontend no tenga que
     * traducir entre las dos.
     *
     * @return array{
     *   cells: list<array{weekday: int, hour: int, revenue: float, sales_count: int}>,
     *   peak: array{weekday: int, hour: int, revenue: float}|null,
     *   slow: array{weekday: int, hour: int, revenue: float}|null,
     * }
     */
    private function heatmap(Business $business, int $days = 30): array
    {
        $from = Carbon::now()->subDays($days - 1)->startOfDay();
        $to = Carbon::now()->endOfDay();

        $rows = Sale::where('business_id', $business->id)
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->whereBetween('closed_at', [$from, $to])
            ->selectRaw('(DAYOFWEEK(closed_at) - 1) as weekday')
            ->selectRaw('HOUR(closed_at) as hour')
            ->selectRaw('SUM(total) as revenue')
            ->selectRaw('COUNT(*) as sales_count')
            ->groupBy('weekday', 'hour')
            ->get()
            ->keyBy(fn ($row) => $row->weekday.'-'.$row->hour);

        $cells = [];
        foreach (range(0, 6) as $weekday) {
            foreach (range(0, 23) as $hour) {
                $row = $rows->get("{$weekday}-{$hour}");
                $cells[] = [
                    'weekday' => $weekday,
                    'hour' => $hour,
                    'revenue' => $row ? (float) $row->revenue : 0.0,
                    'sales_count' => $row ? (int) $row->sales_count : 0,
                ];
            }
        }

        $withActivity = array_filter($cells, fn ($c) => $c['sales_count'] > 0);
        $peak = null;
        $slow = null;
        if ($withActivity !== []) {
            $peakCell = collect($withActivity)->sortByDesc('revenue')->first();
            $slowCell = collect($withActivity)->sortBy('revenue')->first();
            $peak = ['weekday' => $peakCell['weekday'], 'hour' => $peakCell['hour'], 'revenue' => $peakCell['revenue']];
            $slow = ['weekday' => $slowCell['weekday'], 'hour' => $slowCell['hour'], 'revenue' => $slowCell['revenue']];
        }

        return ['cells' => $cells, 'peak' => $peak, 'slow' => $slow];
    }

    /**
     * @return array<string, mixed>
     */
    private function period(Business $business, int $year, int $month, bool $canViewProfit): array
    {
        $businessId = $business->id;
        $start = Carbon::create($year, $month)->startOfMonth()->startOfDay();
        $end = Carbon::create($year, $month)->endOfMonth()->endOfDay();

        $prevMonth = Carbon::create($year, $month)->subMonth();
        $prevStart = $prevMonth->copy()->startOfMonth()->startOfDay();
        $prevEnd = $prevMonth->copy()->endOfMonth()->endOfDay();

        $current = $this->revenueTotals($businessId, $start, $end);
        $previous = $this->revenueTotals($businessId, $prevStart, $prevEnd);

        $unitsSold = (float) SaleItem::whereHas('sale', fn ($q) => $q
            ->where('business_id', $businessId)
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->whereBetween('closed_at', [$start, $end])
        )->sum('quantity');

        $expensesTotal = (float) Expense::where('business_id', $businessId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->sum('value');

        // Dos consultas separadas a proposito: sumar cart_discount_amount con
        // un join a sale_items lo repetiria una vez por cada item de la
        // venta (join 1-a-N) - un SUM(DISTINCT ...) para "arreglarlo"
        // fusionaria de mas ventas distintas que por casualidad comparten el
        // mismo monto de descuento de carrito, subestimando el total.
        $itemDiscounts = (float) SaleItem::whereHas('sale', fn ($q) => $q
            ->where('business_id', $businessId)
            ->where('status', 'closed')
            ->whereBetween('closed_at', [$start, $end])
        )->sum('discount_amount');

        $cartDiscounts = (float) Sale::where('business_id', $businessId)
            ->where('status', 'closed')
            ->whereBetween('closed_at', [$start, $end])
            ->sum('cart_discount_amount');

        $discountsTotal = $itemDiscounts + $cartDiscounts;

        $receivablesOutstanding = $business->hasFeature('receivables')
            ? Receivable::where('business_id', $businessId)->where('status', 'pending')
            : null;

        return [
            'year' => $year,
            'month' => $month,
            'summary' => [
                'revenue' => $current['revenue'],
                'sales_count' => $current['sales_count'],
                'avg_ticket' => $current['avg_ticket'],
                'units_sold' => $unitsSold,
                'expenses_total' => $expensesTotal,
                'net_estimate' => round($current['revenue'] - $expensesTotal, 2),
                'revenue_change_pct' => $this->growthPct($current['revenue'], $previous['revenue']),
            ],
            'discounts' => [
                'total' => $discountsTotal,
                'pct_of_revenue' => $current['revenue'] > 0 ? round(($discountsTotal / $current['revenue']) * 100, 1) : null,
            ],
            'receivables' => $business->hasFeature('receivables') ? [
                'outstanding_total' => (float) $receivablesOutstanding->sum('amount'),
                'outstanding_count' => $receivablesOutstanding->count(),
                'collected_this_period' => (float) Receivable::where('business_id', $businessId)
                    ->where('status', 'paid')
                    ->whereBetween('paid_at', [$start, $end])
                    ->sum('amount'),
            ] : null,
            'top_products' => $this->productRotation($businessId, $start, $end, 'desc', $current['revenue']),
            'bottom_products' => $this->productRotation($businessId, $start, $end, 'asc', $current['revenue']),
            'top_services' => $business->hasFeature('services') ? $this->topServices($businessId, $start, $end) : null,
            'profit' => $canViewProfit ? $this->profitSummary($businessId, $start, $end) : null,
        ];
    }

    /**
     * Margen de ganancia real del mes: solo cuenta productos con costo
     * configurado (ver ProductProfitBreakdown - asumir costo 0 inventaria un
     * margen falso del 100%). Gateado en el controlador por accounting.manage,
     * no por reports.sales: expone la rentabilidad real del negocio, el mismo
     * criterio que ya usa ManagerialAccountingService.
     *
     * @return array{
     *   revenue: float, cost: float, profit: float, margin_pct: float|null,
     *   uncosted_products_count: int, uncosted_revenue: float,
     *   top_products: list<array{product_id: int, name: string, sku: string|null, qty_sold: int, revenue: float, cost_total: float, profit: float, margin_pct: float|null}>,
     * }
     */
    private function profitSummary(int $businessId, Carbon $start, Carbon $end): array
    {
        $breakdown = ProductProfitBreakdown::forPeriod($businessId, $start, $end);

        return [
            'revenue' => $breakdown['total_revenue'],
            'cost' => $breakdown['total_cost'],
            'profit' => $breakdown['total_profit'],
            'margin_pct' => $breakdown['margin_pct'],
            'uncosted_products_count' => $breakdown['uncosted']['products_count'],
            'uncosted_revenue' => $breakdown['uncosted']['total_revenue'],
            'top_products' => array_slice($breakdown['lines'], 0, 10),
        ];
    }

    /**
     * Porteado de BusinessInsightsService::productSalesAggregates() del
     * legacy, con el agregado de impacto (% del ingreso de ventas del mes)
     * que el dueño pidio explicitamente.
     *
     * @return list<array{product_id: int, name: string, sku: string|null, quantity: float, revenue: float, pct_of_revenue: float|null}>
     */
    private function productRotation(int $businessId, Carbon $start, Carbon $end, string $direction, float $periodSalesRevenue, int $limit = 5): array
    {
        $rows = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.business_id', $businessId)
            ->where('sales.status', 'closed')
            ->where('sales.is_non_revenue', false)
            ->where('sales.is_credit', false)
            ->whereBetween('sales.closed_at', [$start, $end])
            ->whereNull('products.deleted_at')
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id as product_id')
            ->selectRaw('MAX(products.name) as name')
            ->selectRaw('MAX(products.sku) as sku')
            ->selectRaw('SUM(sale_items.quantity) as quantity')
            ->selectRaw('SUM(sale_items.subtotal - COALESCE(sale_items.discount_amount, 0)) as revenue')
            ->orderBy('quantity', $direction)
            ->orderBy('sale_items.product_id')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'product_id' => (int) $row->product_id,
            'name' => (string) $row->name,
            'sku' => $row->sku,
            'quantity' => (float) $row->quantity,
            'revenue' => (float) $row->revenue,
            'pct_of_revenue' => $periodSalesRevenue > 0 ? round(((float) $row->revenue / $periodSalesRevenue) * 100, 1) : null,
        ])->values()->all();
    }

    /**
     * Porteado de BusinessInsightsService::topServices() del legacy.
     *
     * @return list<array{name: string, orders_count: int, collected: float}>
     */
    private function topServices(int $businessId, Carbon $start, Carbon $end, int $limit = 5): array
    {
        $rows = DB::table('service_orders as so')
            ->leftJoin('service_payments as sp', function ($join) use ($start, $end) {
                $join->on('sp.service_order_id', '=', 'so.id')
                    ->whereBetween('sp.created_at', [$start, $end]);
            })
            ->where('so.business_id', $businessId)
            ->whereBetween('so.created_at', [$start, $end])
            ->whereNull('so.deleted_at')
            ->where('so.status', '!=', 'cancelled')
            ->groupBy('so.service_name')
            ->selectRaw('so.service_name as name')
            ->selectRaw('COUNT(DISTINCT so.id) as orders_count')
            ->selectRaw('COALESCE(SUM(sp.amount), 0) as collected')
            ->orderByDesc('orders_count')
            ->orderByDesc('collected')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'name' => (string) $row->name,
            'orders_count' => (int) $row->orders_count,
            'collected' => (float) $row->collected,
        ])->values()->all();
    }

    private function growthPct(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
