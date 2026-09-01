<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Carbon as SupportCarbon;

/**
 * Comparativo entre sedes de un mismo negocio.
 *
 * Es la otra mitad de multisede: el resto del sistema ya responde "como va
 * ESTA sede" porque todo filtra por la sede activa. Esto responde "cual va
 * mejor", que es la pregunta que hace el dueño y que ninguna pantalla
 * scopeada puede contestar.
 *
 * Rompe el scope a proposito (withoutGlobalScope) en vez de leer el contexto:
 * un comparativo que solo viera la sede activa no compararia nada.
 *
 * El criterio de "venta con ingreso" es el mismo de BusinessOverviewService:
 * cerrada, no cortesia y no fiada (el fiado entra al ingreso cuando se cobra,
 * no cuando se vende). Si se separaran, dos pantallas del mismo panel darian
 * cifras distintas para el mismo dia.
 */
class BranchComparisonService
{
    /**
     * @return array{
     *   from: string, to: string,
     *   branches: list<array{branch_id: int, name: string, is_main: bool, sales_count: int, revenue: float, avg_ticket: float, expenses: float, net: float, revenue_share_pct: float|null}>,
     *   totals: array{sales_count: int, revenue: float, expenses: float, net: float},
     * }
     */
    public function forPeriod(int $businessId, Carbon $from, Carbon $to): array
    {
        $branches = Branch::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get(['id', 'name', 'is_main']);

        $salesByBranch = Sale::withoutGlobalScope('branch')
            ->where('business_id', $businessId)
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->whereBetween('closed_at', [$from, $to])
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) as sales_count, COALESCE(SUM(total), 0) as revenue')
            ->get()
            ->keyBy('branch_id');

        $expensesByBranch = Expense::withoutGlobalScope('branch')
            ->where('business_id', $businessId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('branch_id')
            // `value`, no `amount`: asi se llama la columna en el schema
            // legacy, y es la misma que suma BusinessOverviewService.
            ->selectRaw('branch_id, COALESCE(SUM(value), 0) as total')
            ->get()
            ->keyBy('branch_id');

        $totalRevenue = (float) $salesByBranch->sum('revenue');

        $rows = $branches->map(function (Branch $branch) use ($salesByBranch, $expensesByBranch, $totalRevenue) {
            $sales = $salesByBranch->get($branch->id);
            $count = (int) ($sales->sales_count ?? 0);
            $revenue = round((float) ($sales->revenue ?? 0), 2);
            $expenses = round((float) ($expensesByBranch->get($branch->id)->total ?? 0), 2);

            return [
                'branch_id' => $branch->id,
                'name' => $branch->name,
                'is_main' => (bool) $branch->is_main,
                'sales_count' => $count,
                'revenue' => $revenue,
                // Redondeado a peso: en COP los centavos de un ticket
                // promedio son ruido, igual que en BusinessOverviewService.
                'avg_ticket' => $count > 0 ? round($revenue / $count) : 0.0,
                'expenses' => $expenses,
                'net' => round($revenue - $expenses, 2),
                // Cuanto del ingreso del negocio aporta esta sede: es lo que
                // convierte una lista de cifras en una comparacion.
                'revenue_share_pct' => $totalRevenue > 0 ? round($revenue * 100 / $totalRevenue, 1) : null,
            ];
        })->values()->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'branches' => $rows,
            'totals' => [
                'sales_count' => (int) $salesByBranch->sum('sales_count'),
                'revenue' => round($totalRevenue, 2),
                'expenses' => round((float) $expensesByBranch->sum('total'), 2),
                'net' => round($totalRevenue - (float) $expensesByBranch->sum('total'), 2),
            ],
        ];
    }

    /**
     * Rango por defecto: el mes corriente, igual que el resto de los
     * reportes del panel.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolveRange(?string $from, ?string $to): array
    {
        $start = $from ? SupportCarbon::parse($from)->startOfDay() : SupportCarbon::now()->startOfMonth();
        $end = $to ? SupportCarbon::parse($to)->endOfDay() : SupportCarbon::now()->endOfDay();

        return [$start, $end];
    }
}
