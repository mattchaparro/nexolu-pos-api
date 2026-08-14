<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\SendsReceipts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSaleRequest;
use App\Http\Resources\Api\V1\SaleResource;
use App\Models\Sale;
use App\Services\ReceiptPdfService;
use App\Services\SaleService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SaleController extends Controller
{
    use SendsReceipts;

    public function __construct(private SaleService $saleService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Sale::with('items.product')->orderByDesc('id');

        if ($request->filled('date')) {
            $query->whereDate('closed_at', $request->input('date'));
        }

        return SaleResource::collection($query->paginate(20)->withQueryString());
    }

    public function store(StoreSaleRequest $request): SaleResource
    {
        $sale = $this->saleService->createSale($request->user(), $request->validated());

        AuditLogger::log($request->user()->hasRole('admin') ? 'sale.created' : 'sale.created.employee', [
            'sale_id' => $sale->id,
            'total' => $sale->total,
            'payment_method' => $sale->payment_method,
        ]);

        return new SaleResource($sale);
    }

    public function show(Sale $sale): SaleResource
    {
        return new SaleResource($sale->load('items.product'));
    }

    public function reverse(Request $request, Sale $sale): Response
    {
        AuditLogger::log('sale.reversed', [
            'sale_id' => $sale->id,
            'invoice_number' => $sale->invoice_number,
            'total' => $sale->total,
        ]);

        $this->saleService->reverseSale($request->user(), $sale);

        return response()->noContent();
    }

    public function receipt(Sale $sale): Response
    {
        return $this->receiptDownloadResponse(app(ReceiptPdfService::class)->forSale($sale));
    }

    public function printReceipt(Request $request, Sale $sale): Response
    {
        return $this->receiptPrintResponse(app(ReceiptPdfService::class)->printSale($sale, $request->boolean('auto_print', true)));
    }

    public function sendReceipt(Request $request, Sale $sale): JsonResponse
    {
        return $this->receiptSendResponse($request, 'sale', $sale->id, 'Recibo de venta');
    }
}
