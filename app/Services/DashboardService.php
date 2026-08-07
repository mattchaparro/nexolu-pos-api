<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\ServicePayment;
use Illuminate\Support\Carbon;

class DashboardService
{
    /**
     * Resumen del dia para el home de la app - portado 1:1 de la logica de
     * Admin/DashboardController del legacy (ver CONTEXT.md del legacy repo).
     *
     * `today_sales` es lo efectivamente recibido hoy, no solo ventas: suma
     * ventas cerradas (sin fiados ni no-revenue) + fiados cobrados hoy +
     * pagos de servicios de hoy. `open_tabs_total` son las cuentas
     * ABIERTAS HOY (no todas las que siguen abiertas), y `pending_receivables`
     * es el saldo pendiente historico completo, no solo el de hoy.
     *
     * @return array{
     *     today_sales: float,
     *     today_count: int,
     *     open_tabs_total: float,
     *     receivables_enabled: bool,
     *     pending_receivables: float,
     *     expenses_enabled: bool,
     *     today_expenses: float,
     * }
     */
    public function todaySummary(Business $business): array
    {
        $today = Carbon::today();

        $revenueSales = Sale::query()
            ->where('business_id', $business->id)
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->whereDate('closed_at', $today);

        $paidReceivablesTotal = Receivable::query()
            ->where('business_id', $business->id)
            ->whereDate('paid_at', $today)
            ->sum('amount');

        $servicePaymentsTotal = ServicePayment::query()
            ->where('business_id', $business->id)
            ->whereDate('created_at', $today)
            ->sum('amount');

        $openTabsTotal = Sale::query()
            ->where('business_id', $business->id)
            ->where('status', 'open')
            ->whereDate('created_at', $today)
            ->sum('total');

        $receivablesEnabled = $business->hasFeature('receivables');
        $pendingReceivables = $receivablesEnabled
            ? Receivable::query()->where('business_id', $business->id)->where('status', 'pending')->sum('balance')
            : 0;

        $expensesEnabled = $business->hasFeature('expenses');
        $todayExpenses = $expensesEnabled
            ? Expense::query()
                ->where('business_id', $business->id)
                ->whereDate('date', $today)
                ->where('scope', 'operacional')
                ->sum('value')
            : 0;

        return [
            'today_sales' => (float) $revenueSales->sum('total') + (float) $paidReceivablesTotal + (float) $servicePaymentsTotal,
            'today_count' => $revenueSales->count(),
            'open_tabs_total' => (float) $openTabsTotal,
            'receivables_enabled' => $receivablesEnabled,
            'pending_receivables' => (float) $pendingReceivables,
            'expenses_enabled' => $expensesEnabled,
            'today_expenses' => (float) $todayExpenses,
        ];
    }
}
