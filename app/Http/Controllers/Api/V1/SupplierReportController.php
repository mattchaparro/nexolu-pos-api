<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\PurchaseLine;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierReportController extends Controller
{
    /** @return array<string, mixed> */
    public function index(Request $request): array
    {
        $business = Business::findOrFail($request->user()->business_id);

        abort_unless($business->canAccessPurchases(), 403);

        $query = PurchaseLine::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->where('purchases.business_id', $business->id)
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
            ])
            ->orderByDesc('purchases.purchased_at')
            ->orderByDesc('purchase_lines.id');

        if ($request->filled('supplier_id')) {
            $query->where('purchases.supplier_id', (int) $request->get('supplier_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('purchase_lines.product_id', (int) $request->get('product_id'));
        }
        if ($request->filled('from')) {
            $query->whereDate('purchases.purchased_at', '>=', $request->get('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('purchases.purchased_at', '<=', $request->get('to'));
        }

        $totals = (clone $query)->toBase()
            ->select([])
            ->selectRaw('SUM(purchase_lines.line_total_cop) as total_cop, SUM(purchase_lines.quantity) as total_qty, COUNT(DISTINCT purchase_lines.purchase_id) as purchase_count')
            ->reorder()
            ->first();

        $paginator = $query->paginate(40);

        $lines = $paginator->map(fn ($l) => [
            'id' => $l->id,
            'purchase_id' => $l->purchase_id,
            'date' => $l->purchase?->purchased_at?->format('Y-m-d'),
            'invoice_number' => $l->purchase?->invoice_number,
            'supplier_name' => $l->purchase?->supplier?->name,
            'item_name' => $l->product?->name ?? ($l->ingredient ? $l->ingredient->name.' ('.$l->ingredient->unit.')' : 'Eliminado'),
            'quantity' => (float) $l->quantity,
            'unit_cost_cop' => (float) $l->unit_cost_cop,
            'line_total_cop' => (float) $l->line_total_cop,
            'notes' => $l->notes,
        ])->values()->all();

        return [
            'data' => $lines,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'totals' => [
                'total_cop' => round((float) ($totals->total_cop ?? 0), 2),
                'total_qty' => (float) ($totals->total_qty ?? 0),
                'purchase_count' => (int) ($totals->purchase_count ?? 0),
            ],
        ];
    }

    public function export(Request $request): StreamedResponse
    {
        $business = Business::findOrFail($request->user()->business_id);

        abort_unless($business->canAccessPurchases(), 403);

        $query = PurchaseLine::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->where('purchases.business_id', $business->id)
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
                'purchases.purchased_at',
                'purchases.invoice_number',
            ])
            ->orderByDesc('purchases.purchased_at')
            ->orderByDesc('purchase_lines.id');

        if ($request->filled('supplier_id')) {
            $query->where('purchases.supplier_id', (int) $request->get('supplier_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('purchase_lines.product_id', (int) $request->get('product_id'));
        }
        if ($request->filled('from')) {
            $query->whereDate('purchases.purchased_at', '>=', $request->get('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('purchases.purchased_at', '<=', $request->get('to'));
        }

        $rows = $query->limit(5000)->get();
        $filename = 'compras-proveedores-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Fecha', 'Factura', 'Proveedor', 'Item', 'Cantidad', 'Costo unitario', 'Total linea', 'Notas'], ';');
            foreach ($rows as $l) {
                fputcsv($out, [
                    $l->purchase?->purchased_at?->format('Y-m-d'),
                    $l->purchase?->invoice_number,
                    $l->purchase?->supplier?->name,
                    $l->product?->name ?? ($l->ingredient ? $l->ingredient->name.' ('.$l->ingredient->unit.')' : 'Eliminado'),
                    $l->quantity,
                    $l->unit_cost_cop,
                    $l->line_total_cop,
                    $l->notes,
                ], ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
