<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\PurchaseLine;
use App\Support\BranchFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporte de compras a proveedores: cuanto se le compra a cada uno, mes a
 * mes, y el detalle de cada linea. Los mismos filtros (proveedor, producto,
 * fechas) valen para el listado, los totales, los agrupados y el CSV - antes
 * estaban copiados en index() y export().
 */
class SupplierReportController extends Controller
{
    /** @return array<string, mixed> */
    public function index(Request $request): array
    {
        $business = $this->business($request);
        $query = $this->filteredLines($request, $business);

        $totals = $this->aggregate($query)
            ->selectRaw('SUM(purchase_lines.line_total_cop) as total_cop, SUM(purchase_lines.quantity) as total_qty, COUNT(DISTINCT purchase_lines.purchase_id) as purchase_count, COUNT(DISTINCT purchases.supplier_id) as supplier_count')
            ->first();

        $byMonth = $this->aggregate($query)
            ->selectRaw("DATE_FORMAT(purchases.purchased_at, '%Y-%m') as month, SUM(purchase_lines.line_total_cop) as total_cop, COUNT(DISTINCT purchase_lines.purchase_id) as purchase_count")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'total_cop' => round((float) $row->total_cop, 2),
                'purchase_count' => (int) $row->purchase_count,
            ])->all();

        $totalCop = round((float) ($totals->total_cop ?? 0), 2);

        $bySupplier = $this->aggregate($query)
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->selectRaw('purchases.supplier_id, suppliers.name as supplier_name, SUM(purchase_lines.line_total_cop) as total_cop, COUNT(DISTINCT purchase_lines.purchase_id) as purchase_count, MAX(purchases.purchased_at) as last_purchase_at')
            ->groupBy('purchases.supplier_id', 'suppliers.name')
            ->orderByDesc('total_cop')
            ->get()
            ->map(fn ($row) => [
                'supplier_id' => $row->supplier_id ? (int) $row->supplier_id : null,
                'supplier_name' => $row->supplier_id ? ($row->supplier_name ?? 'Proveedor eliminado') : 'Sin proveedor',
                'total_cop' => round((float) $row->total_cop, 2),
                'purchase_count' => (int) $row->purchase_count,
                'pct_of_total' => $totalCop > 0 ? round(((float) $row->total_cop / $totalCop) * 100, 1) : null,
                'last_purchase_at' => $row->last_purchase_at ? substr((string) $row->last_purchase_at, 0, 10) : null,
            ])->all();

        $paginator = $query
            ->orderByDesc('purchases.purchased_at')
            ->orderByDesc('purchase_lines.id')
            ->paginate(40);

        return [
            'data' => $paginator->map(fn (PurchaseLine $line) => $this->lineRow($line))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'totals' => [
                'total_cop' => $totalCop,
                'total_qty' => (float) ($totals->total_qty ?? 0),
                'purchase_count' => (int) ($totals->purchase_count ?? 0),
                // COUNT(DISTINCT) no cuenta NULL: "sin proveedor" no suma como uno.
                'supplier_count' => (int) ($totals->supplier_count ?? 0),
                'month_count' => count($byMonth),
            ],
            'by_month' => $byMonth,
            'by_supplier' => $bySupplier,
        ];
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filteredLines($request, $this->business($request))
            ->orderByDesc('purchases.purchased_at')
            ->orderByDesc('purchase_lines.id')
            ->limit(5000)
            ->get();
        $filename = 'compras-proveedores-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Fecha', 'Factura', 'Proveedor', 'Item', 'Cantidad', 'Costo unitario', 'Total linea', 'Notas'], ';');
            foreach ($rows as $line) {
                $row = $this->lineRow($line);
                fputcsv($out, [
                    $row['date'], $row['invoice_number'], $row['supplier_name'] ?? 'Sin proveedor', $row['item_name'],
                    $row['quantity'], $row['unit_cost_cop'], $row['line_total_cop'], $row['notes'],
                ], ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function business(Request $request): Business
    {
        $business = Business::findOrFail($request->user()->business_id);
        abort_unless($business->canAccessPurchases(), 403);

        return $business;
    }

    /**
     * Lineas de compra del negocio con los filtros del reporte.
     * supplier_id=none: las compras sin proveedor.
     *
     * @return Builder<PurchaseLine>
     */
    private function filteredLines(Request $request, Business $business): Builder
    {
        $query = PurchaseLine::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->where('purchases.business_id', $business->id)
            ->tap(fn ($q) => BranchFilter::apply($q, 'purchases'))
            ->with([
                'purchase:id,supplier_id,purchased_at,invoice_number',
                'purchase.supplier:id,name',
                'product:id,name',
                'ingredient:id,name,unit',
            ])
            ->select([
                'purchase_lines.id',
                'purchase_lines.purchase_id',
                'purchase_lines.product_id',
                'purchase_lines.ingredient_id',
                'purchase_lines.quantity',
                'purchase_lines.unit_cost_cop',
                'purchase_lines.line_total_cop',
                'purchase_lines.notes',
                'purchases.supplier_id',
                'purchases.purchased_at',
                'purchases.invoice_number',
            ]);

        if ($request->filled('supplier_id')) {
            $request->input('supplier_id') === 'none'
                ? $query->whereNull('purchases.supplier_id')
                : $query->where('purchases.supplier_id', $request->integer('supplier_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('purchase_lines.product_id', $request->integer('product_id'));
        }
        if ($request->filled('from')) {
            $query->whereDate('purchases.purchased_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('purchases.purchased_at', '<=', $request->date('to'));
        }

        return $query;
    }

    /** Copia de la consulta filtrada lista para agregar (sin select, orden ni eager loads). */
    private function aggregate(Builder $query): \Illuminate\Database\Query\Builder
    {
        return (clone $query)->toBase()->select([])->reorder();
    }

    /** @return array<string, mixed> */
    private function lineRow(PurchaseLine $line): array
    {
        return [
            'id' => $line->id,
            'purchase_id' => $line->purchase_id,
            'date' => $line->purchase?->purchased_at?->format('Y-m-d'),
            'invoice_number' => $line->purchase?->invoice_number,
            'supplier_name' => $line->purchase?->supplier?->name,
            'item_name' => $line->product?->name ?? ($line->ingredient ? $line->ingredient->name.' ('.$line->ingredient->unit.')' : 'Eliminado'),
            'quantity' => (float) $line->quantity,
            'unit_cost_cop' => (float) $line->unit_cost_cop,
            'line_total_cop' => (float) $line->line_total_cop,
            'notes' => $line->notes,
        ];
    }
}
