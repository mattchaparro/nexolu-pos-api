<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CloseAccountingMonthRequest;
use App\Http\Resources\Api\V1\AccountingPeriodClosingResource;
use App\Models\AccountingPeriodClosing;
use App\Services\ManagerialAccountingService;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountingController extends Controller
{
    public function __construct(private ManagerialAccountingService $accounting) {}

    /** @return array<string, mixed> */
    public function monthly(Request $request): array
    {
        [$year, $month] = $this->yearMonth($request);
        $businessId = (int) $request->user()->business_id;

        return array_merge(
            $this->accounting->monthlyReport($businessId, $year, $month),
            $this->accounting->monthlyLines($businessId, $year, $month),
        );
    }

    /** @return array<string, mixed> */
    public function annual(Request $request): array
    {
        $year = max(2020, $request->integer('year', now()->year));
        $businessId = (int) $request->user()->business_id;

        return $this->accounting->annualReport($businessId, $year);
    }

    public function closings(Request $request): AnonymousResourceCollection
    {
        $closings = AccountingPeriodClosing::query()
            ->where('business_id', $request->user()->business_id)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(12)
            ->get();

        return AccountingPeriodClosingResource::collection($closings);
    }

    public function closeMonth(CloseAccountingMonthRequest $request): AccountingPeriodClosingResource
    {
        $closing = $this->accounting->closeMonth(
            (int) $request->user()->business_id,
            (int) $request->user()->id,
            (int) $request->validated('year'),
            (int) $request->validated('month'),
            $request->validated('notes'),
        );

        AuditLogger::log('accounting.month_closed', [
            'accounting_period_closing_id' => $closing->id,
            'year' => $closing->year,
            'month' => $closing->month,
        ]);

        return new AccountingPeriodClosingResource($closing->load('closedByUser'));
    }

    public function exportMonthly(Request $request): StreamedResponse
    {
        [$year, $month] = $this->yearMonth($request);
        $businessId = (int) $request->user()->business_id;

        $report = array_merge(
            $this->accounting->monthlyReport($businessId, $year, $month),
            $this->accounting->monthlyLines($businessId, $year, $month),
        );

        $filename = sprintf('contabilidad-%d-%02d.csv', $year, $month);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['REPORTE DE CONTABILIDAD - '.$report['period_label']], ';');
            fputcsv($out, ['Generado: '.now()->format('d/m/Y H:i')], ';');
            fputcsv($out, [''], ';');
            fputcsv($out, ['RESUMEN'], ';');
            fputcsv($out, ['Ingresos', $report['income']], ';');
            fputcsv($out, ['Gastos', $report['expenses']], ';');
            fputcsv($out, ['Utilidad neta', $report['net_result']], ';');
            fputcsv($out, ['Numero de ventas', $report['sales_count']], ';');
            fputcsv($out, ['Numero de gastos', $report['expenses_count']], ';');
            fputcsv($out, ['Fiados cobrados', $report['receivables_collected']], ';');
            fputcsv($out, [''], ';');
            fputcsv($out, ['INGRESOS'], ';');
            fputcsv($out, ['Fecha', 'Descripcion', 'Tipo', 'Monto'], ';');
            foreach ($report['income_lines'] as $line) {
                fputcsv($out, [$line['date'], $line['description'], $line['type_label'], $line['amount']], ';');
            }
            fputcsv($out, ['', '', 'Total ingresos', $report['income']], ';');
            fputcsv($out, [''], ';');
            fputcsv($out, ['GASTOS'], ';');
            fputcsv($out, ['Fecha', 'Descripcion', 'Categoria', 'Monto'], ';');
            foreach ($report['expense_lines'] as $line) {
                fputcsv($out, [$line['date'], $line['description'], $line['type_name'], $line['amount']], ';');
            }
            fputcsv($out, ['', '', 'Total gastos', $report['expenses']], ';');
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: int, 1: int} */
    private function yearMonth(Request $request): array
    {
        $year = max(2020, $request->integer('year', now()->year));
        $month = min(12, max(1, $request->integer('month', now()->month)));

        return [$year, $month];
    }
}
