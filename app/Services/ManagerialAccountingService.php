<?php

namespace App\Services;

use App\Models\AccountingPeriodClosing;
use App\Models\Expense;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\ServicePayment;
use App\Support\ProductProfitBreakdown;
use Carbon\Carbon;

/**
 * Contabilidad gerencial: P&L mensual/anual de UN negocio cliente del POS
 * (ingresos vs gastos, utilidad neta), con cierre de mes opcional que
 * congela el resultado. No confundir con App\Services\SuperAdmin\PlatformFinanceService,
 * que es la plata de Nexolu como plataforma SaaS, no la contabilidad del
 * negocio que la usa.
 */
class ManagerialAccountingService
{
    /**
     * @return array{
     *     year: int, month: int, period_label: string,
     *     income: float, expenses: float, net_result: float,
     *     sales_count: int, expenses_count: int, receivables_collected: float,
     *     is_closed: bool, closed_at: ?string, closed_by: ?string, closed_notes: ?string,
     * }
     */
    public function monthlyReport(int $businessId, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();
        $totals = $this->calculateTotals($businessId, $start, $end);

        $closed = AccountingPeriodClosing::query()
            ->where('business_id', $businessId)
            ->where('year', $year)
            ->where('month', $month)
            ->latest('id')
            ->first();

        $locale = (string) config('app.locale', 'es');

        return [
            'year' => $year,
            'month' => $month,
            'period_label' => $start->copy()->locale($locale)->translatedFormat('F Y'),
            'income' => $totals['income'],
            'expenses' => $totals['expenses'],
            'net_result' => $totals['income'] - $totals['expenses'],
            'sales_count' => $totals['sales_count'],
            'expenses_count' => $totals['expenses_count'],
            'receivables_collected' => $totals['receivables_collected'],
            'is_closed' => (bool) $closed,
            'closed_at' => $closed?->closed_at?->toDateTimeString(),
            'closed_by' => $closed?->closedByUser?->name,
            'closed_notes' => $closed?->notes,
        ];
    }

    /**
     * Linea a linea de ingresos y gastos del mes. Separado de monthlyReport()
     * para no inflar el JSON que closeMonth() congela en `summary`.
     *
     * @return array{income_lines: list<array<string, mixed>>, expense_lines: list<array<string, mixed>>, product_profit_lines: array<string, mixed>}
     */
    public function monthlyLines(int $businessId, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        return [
            'income_lines' => $this->incomeLines($businessId, $start, $end),
            'expense_lines' => $this->expenseLines($businessId, $start, $end),
            'product_profit_lines' => $this->productProfitLines($businessId, $start, $end),
        ];
    }

    /**
     * @return array{year: int, months: list<array<string, mixed>>, income_total: float, expenses_total: float, net_total: float}
     */
    public function annualReport(int $businessId, int $year): array
    {
        $locale = (string) config('app.locale', 'es');

        $months = collect(range(1, 12))->map(function (int $month) use ($businessId, $year, $locale) {
            $start = Carbon::create($year, $month, 1)->startOfMonth();
            $end = (clone $start)->endOfMonth();
            $totals = $this->calculateTotals($businessId, $start, $end);

            return [
                'month' => $month,
                'label' => $start->copy()->locale($locale)->translatedFormat('M'),
                'income' => $totals['income'],
                'expenses' => $totals['expenses'],
                'net_result' => $totals['income'] - $totals['expenses'],
                'sales_count' => $totals['sales_count'],
            ];
        });

        return [
            'year' => $year,
            'months' => $months->values()->all(),
            'income_total' => round((float) $months->sum('income'), 2),
            'expenses_total' => round((float) $months->sum('expenses'), 2),
            'net_total' => round((float) $months->sum('net_result'), 2),
        ];
    }

    public function closeMonth(int $businessId, int $closedByUserId, int $year, int $month, ?string $notes = null): AccountingPeriodClosing
    {
        $report = $this->monthlyReport($businessId, $year, $month);

        return AccountingPeriodClosing::query()->updateOrCreate(
            [
                'business_id' => $businessId,
                'year' => $year,
                'month' => $month,
            ],
            [
                'status' => AccountingPeriodClosing::STATUS_CLOSED,
                'summary' => $report,
                'notes' => $notes,
                'closed_by_user_id' => $closedByUserId,
                'closed_at' => now(),
            ]
        );
    }

    /**
     * @return array{income: float, expenses: float, sales_count: int, expenses_count: int, receivables_collected: float}
     */
    private function calculateTotals(int $businessId, Carbon $start, Carbon $end): array
    {
        $directSalesQuery = Sale::query()
            ->where('business_id', $businessId)
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->whereBetween('closed_at', [$start, $end]);

        $receivablesPaidQuery = Receivable::query()
            ->where('business_id', $businessId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$start, $end]);

        $servicePaymentsQuery = ServicePayment::query()
            ->where('business_id', $businessId)
            ->whereBetween('created_at', [$start, $end]);

        $expensesQuery = Expense::query()
            ->where('business_id', $businessId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()]);

        $directIncome = (float) $directSalesQuery->sum('total');
        $receivablesCollected = (float) $receivablesPaidQuery->sum('amount');
        $servicePaymentsCollected = (float) $servicePaymentsQuery->sum('amount');
        $expenses = (float) $expensesQuery->sum('value');

        return [
            'income' => round($directIncome + $receivablesCollected + $servicePaymentsCollected, 2),
            'expenses' => round($expenses, 2),
            'sales_count' => (int) $directSalesQuery->count(),
            'expenses_count' => (int) $expensesQuery->count(),
            'receivables_collected' => round($receivablesCollected, 2),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function incomeLines(int $businessId, Carbon $start, Carbon $end): array
    {
        $salesLines = Sale::query()
            ->select(['id', 'invoice_number', 'total', 'customer_name', 'closed_at'])
            ->where('business_id', $businessId)
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            // Por closed_at: una cuenta abierta puede llevar dias abierta antes
            // de cobrarse, y el ingreso pertenece al mes en que se cobro, no al
            // mes en que se abrio la mesa.
            ->whereBetween('closed_at', [$start, $end])
            ->orderBy('closed_at')
            ->get()
            ->map(fn (Sale $sale) => [
                'date' => $sale->closed_at->format('d/m/Y H:i'),
                'description' => ($sale->invoice_number ?: "Venta #{$sale->id}").($sale->customer_name ? " · {$sale->customer_name}" : ''),
                'type' => 'sale',
                'type_label' => 'Venta',
                'amount' => (float) $sale->total,
            ]);

        $receivablesLines = Receivable::query()
            ->select(['id', 'customer_name', 'amount', 'paid_at'])
            ->where('business_id', $businessId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$start, $end])
            ->orderBy('paid_at')
            ->get()
            ->map(fn (Receivable $receivable) => [
                'date' => $receivable->paid_at->format('d/m/Y H:i'),
                'description' => 'Cobro fiado'.($receivable->customer_name ? " · {$receivable->customer_name}" : ''),
                'type' => 'receivable',
                'type_label' => 'Fiado cobrado',
                'amount' => (float) $receivable->amount,
            ]);

        $servicePaymentLines = ServicePayment::query()
            ->select(['id', 'service_order_id', 'amount', 'created_at'])
            ->with('order:id,service_name,client_id', 'order.client:id,name')
            ->where('business_id', $businessId)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get()
            ->map(fn (ServicePayment $payment) => [
                'date' => $payment->created_at->format('d/m/Y H:i'),
                'description' => 'Servicio: '.($payment->order?->service_name ?? 'Orden #'.$payment->service_order_id)
                    .($payment->order?->client?->name ? ' · '.$payment->order->client->name : ''),
                'type' => 'service_payment',
                'type_label' => 'Pago de servicio',
                'amount' => (float) $payment->amount,
            ]);

        return $salesLines->concat($receivablesLines)->concat($servicePaymentLines)
            ->sortBy('date')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expenseLines(int $businessId, Carbon $start, Carbon $end): array
    {
        return Expense::query()
            ->select(['id', 'description', 'value', 'date', 'type_id'])
            ->with('type:id,name')
            ->where('business_id', $businessId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (Expense $expense) => [
                'date' => (string) $expense->date,
                'description' => $expense->description ?: 'Sin descripcion',
                'type_name' => $expense->type?->name ?: 'Sin categoria',
                'amount' => (float) $expense->value,
            ])
            ->all();
    }

    /**
     * Ganancia bruta por producto vendido en el periodo. Delega en
     * App\Support\ProductProfitBreakdown (compartida con "Mi negocio") - ver
     * esa clase para el detalle de como se calcula el costo y por que los
     * productos sin costo configurado quedan aparte en 'uncosted'.
     *
     * @return array{
     *     lines: list<array<string, mixed>>, total_profit: float, total_revenue: float,
     *     uncosted: array{lines: list<array<string, mixed>>, total_revenue: float, products_count: int},
     * }
     */
    private function productProfitLines(int $businessId, Carbon $start, Carbon $end): array
    {
        return ProductProfitBreakdown::forPeriod($businessId, $start, $end);
    }
}
