<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\SalesReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReportController extends Controller
{
    public function __construct(private SalesReportService $service) {}

    /**
     * Acepta `date` (un solo dia, retrocompatible) o `date_from`/`date_to`
     * (rango, usado por el modulo "Resumen del dia" del frontend).
     *
     * @return array<string, mixed>
     */
    public function daily(Request $request): array
    {
        $business = Business::findOrFail($request->user()->business_id);
        $date = $this->parseDate($request->string('date', today()->toDateString())->toString());
        $dateFrom = $request->filled('date_from') ? $this->parseDate($request->string('date_from')->toString()) : $date;
        $dateTo = $request->filled('date_to') ? $this->parseDate($request->string('date_to')->toString()) : null;

        return $this->service->dailySummary($business, $dateFrom, $dateTo);
    }

    private function parseDate(string $date): string
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $date)->toDateString();
        } catch (\Throwable) {
            return today()->toDateString();
        }
    }

    /** @return array<string, mixed> */
    public function history(Request $request): array
    {
        $business = Business::findOrFail($request->user()->business_id);

        $paginator = $this->service->salesHistory(
            $business,
            $request->string('from', today()->toDateString())->toString(),
            $request->string('to', today()->toDateString())->toString(),
            min(100, max(1, $request->integer('per_page', 20))),
            $request->only(['status', 'payment_method', 'search']),
        );

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'payment_method_options' => $this->service->paymentMethodOptions($business),
        ];
    }

    public function historyExport(Request $request): StreamedResponse
    {
        $business = Business::findOrFail($request->user()->business_id);

        $rows = $this->service->salesHistoryForExport(
            $business,
            $request->string('from', today()->toDateString())->toString(),
            $request->string('to', today()->toDateString())->toString(),
            $request->only(['status', 'payment_method', 'search']),
        );

        $filename = 'ventas-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Factura', 'Fecha', 'Estado', 'Total', 'Metodo de pago', 'Cliente', 'Telefono', 'Vendedor', 'Es cortesia', 'Es fiado', 'Productos'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['id'],
                    $r['invoice_number'],
                    $r['created_at'],
                    $r['status'] === 'closed' ? 'Cerrada' : 'Abierta',
                    $r['total'],
                    $r['payment_method'],
                    $r['customer_name'],
                    $r['customer_phone'],
                    $r['user_name'],
                    $r['is_non_revenue'] ? 'Sí' : 'No',
                    $r['is_credit'] ? 'Sí' : 'No',
                    $r['items_preview'],
                ], ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    public function bySeller(Request $request): array
    {
        $business = Business::findOrFail($request->user()->business_id);

        return $this->service->salesBySeller(
            $business,
            $request->string('from', today()->toDateString())->toString(),
            $request->string('to', today()->toDateString())->toString(),
        );
    }

    public function bySellerExport(Request $request): StreamedResponse
    {
        $business = Business::findOrFail($request->user()->business_id);

        $data = $this->service->salesBySeller(
            $business,
            $request->string('from', today()->toDateString())->toString(),
            $request->string('to', today()->toDateString())->toString(),
        );

        $filename = 'ventas-por-vendedor-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Vendedor', 'Ventas', 'Total bruto', 'Ticket promedio', 'Unidades vendidas', 'Ultima venta'], ';');
            foreach ($data['sellers'] as $row) {
                fputcsv($out, [
                    $row['user_name'],
                    $row['sales_count'],
                    $row['gross_total'],
                    $row['avg_ticket'],
                    $row['items_sold'],
                    $row['last_sale_at'],
                ], ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    public function cashClosings(Request $request): array
    {
        $business = Business::findOrFail($request->user()->business_id);

        [$dateFrom, $dateTo] = $this->service->resolveCashClosingDates(
            $request->string('month')->toString(),
            $request->string('date_from')->toString(),
            $request->string('date_to')->toString(),
        );

        return $this->service->cashClosings($business, $dateFrom, $dateTo);
    }

    public function cashClosingsExport(Request $request): StreamedResponse
    {
        $business = Business::findOrFail($request->user()->business_id);

        [$dateFrom, $dateTo] = $this->service->resolveCashClosingDates(
            $request->string('month')->toString(),
            $request->string('date_from')->toString(),
            $request->string('date_to')->toString(),
        );

        $data = $this->service->cashClosings($business, $dateFrom, $dateTo);

        $filename = 'cierres-de-caja-'.$dateFrom.'-'.$dateTo.'.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Fecha', 'Caja apertura', 'Total ventas', 'Total efectivo',
                'Otros metodos', 'Total gastos', 'Caja esperada',
                'Caja real', 'Base siguiente dia', 'Diferencia', 'Cerrado por',
            ], ';');
            foreach ($data['closings'] as $c) {
                fputcsv($out, [
                    $c['date'],
                    $c['opening_cash'],
                    $c['total_sales'],
                    $c['total_cash'],
                    $c['total_other'],
                    $c['total_expenses'],
                    $c['expected_cash'],
                    $c['actual_cash'],
                    $c['base_for_next_day'],
                    $c['difference'],
                    $c['closed_by_name'],
                ], ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
