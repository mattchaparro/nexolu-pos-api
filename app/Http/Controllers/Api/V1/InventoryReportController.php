<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\InventoryReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryReportController extends Controller
{
    public function __construct(private InventoryReportService $service) {}

    /** @return array<string, mixed> */
    public function summary(Request $request): array
    {
        $business = $this->authorizedBusiness($request);

        return $this->service->summary($business);
    }

    /** @return array<string, mixed> */
    public function movements(Request $request): array
    {
        $business = $this->authorizedBusiness($request);

        $paginator = $this->service->movements($business, $request->only([
            'type', 'reason_id', 'product_id', 'ingredient_id', 'from', 'to',
        ]));

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function movementsExport(Request $request): StreamedResponse
    {
        $business = $this->authorizedBusiness($request);

        $rows = $this->service->movementsForExport($business, $request->only([
            'type', 'reason_id', 'product_id', 'ingredient_id', 'from', 'to',
        ]));

        $filename = 'movimientos-inventario-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Fecha', 'Tipo', 'Razon', 'Producto', 'Categoria', 'Insumo', 'Cantidad', 'Usuario', 'Notas'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['created_at'],
                    $r['type'],
                    $r['reason_label'],
                    $r['product_name'],
                    $r['product_category'],
                    $r['ingredient_name'],
                    $r['quantity'],
                    $r['user_name'],
                    $r['notes'],
                ], ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    public function margins(Request $request): array
    {
        $business = $this->authorizedBusiness($request);

        return $this->service->margins($business, $request->only([
            'category_id', 'with_sales', 'month', 'date_from', 'date_to',
        ]));
    }

    public function marginsExport(Request $request): StreamedResponse
    {
        $business = $this->authorizedBusiness($request);

        $rows = $this->service->marginsForExport($business, $request->only([
            'category_id', 'with_sales', 'month', 'date_from', 'date_to',
        ]));

        $withSales = (bool) $request->get('with_sales');
        $filename = 'margenes-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $withSales) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            $headers = ['Producto', 'Categoria', 'Stock', 'Precio', 'Costo', 'Margen COP', 'Margen %', 'Ganancia potencial'];
            if ($withSales) {
                $headers[] = 'Unidades vendidas';
                $headers[] = 'Ganancia real (ventas)';
            }
            fputcsv($out, $headers, ';');

            foreach ($rows as $r) {
                $row = [
                    $r['name'],
                    $r['category'],
                    $r['stock'],
                    $r['price'],
                    $r['cost_price'],
                    $r['margin_cop'],
                    $r['margin_pct'],
                    $r['profit_total'],
                ];
                if ($withSales) {
                    $row[] = $r['qty_sold'];
                    $row[] = $r['profit_from_sales'];
                }
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizedBusiness(Request $request): Business
    {
        $business = Business::findOrFail($request->user()->business_id);

        abort_unless(
            $business->hasFeature('inventory_advanced') || $business->hasFeature('ingredients'),
            403,
        );

        return $business;
    }
}
