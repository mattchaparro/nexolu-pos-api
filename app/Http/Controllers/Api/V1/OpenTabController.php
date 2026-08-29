<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CloseOpenTabRequest;
use App\Http\Requests\Api\V1\OpenTabItemsRequest;
use App\Http\Requests\Api\V1\RecordPartialPaymentRequest;
use App\Http\Requests\Api\V1\StoreOpenTabRequest;
use App\Http\Resources\Api\V1\SaleResource;
use App\Models\Sale;
use App\Services\OpenTabService;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class OpenTabController extends Controller
{
    public function __construct(private OpenTabService $openTabService) {}

    public function index(): AnonymousResourceCollection
    {
        return SaleResource::collection(
            Sale::where('status', 'open')
                ->with(['items.product', 'items.productVariant.attributeValues.productAttribute', 'partialPayments', 'table'])
                ->latest()
                ->get()
        );
    }

    public function store(StoreOpenTabRequest $request): SaleResource
    {
        $sale = $this->openTabService->openTab($request->user(), $request->validated());

        AuditLogger::log('tab.opened', ['sale_id' => $sale->id, 'table_id' => $sale->table_id]);

        return new SaleResource($sale);
    }

    public function show(Sale $sale): SaleResource
    {
        return new SaleResource($sale->load('items.product', 'items.productVariant.attributeValues.productAttribute', 'partialPayments', 'paymentSplits', 'table'));
    }

    public function addItems(OpenTabItemsRequest $request, Sale $sale): SaleResource
    {
        $sale = $this->openTabService->addItems($request->user(), $sale, $request->validated('items'));

        AuditLogger::log('tab.items_added', ['sale_id' => $sale->id, 'items' => $request->validated('items')]);

        return new SaleResource($sale);
    }

    public function syncItems(OpenTabItemsRequest $request, Sale $sale): SaleResource
    {
        $sale = $this->openTabService->syncItems($request->user(), $sale, $request->validated('items'));

        AuditLogger::log('tab.items_synced', ['sale_id' => $sale->id, 'items' => $request->validated('items'), 'total' => $sale->total]);

        return new SaleResource($sale);
    }

    public function recordPartialPayment(RecordPartialPaymentRequest $request, Sale $sale): SaleResource
    {
        $sale = $this->openTabService->recordPartialPayment(
            $request->user(),
            $sale,
            (float) $request->validated('amount'),
            $request->validated('payment_method'),
            $request->validated('payer_label'),
        );

        AuditLogger::log('tab.partial_payment', [
            'sale_id' => $sale->id,
            'amount' => $request->validated('amount'),
            'payment_method' => $request->validated('payment_method'),
        ]);

        return new SaleResource($sale->load('items.product', 'items.productVariant.attributeValues.productAttribute', 'partialPayments', 'paymentSplits'));
    }

    public function close(CloseOpenTabRequest $request, Sale $sale): SaleResource
    {
        $sale = $this->openTabService->close($request->user(), $sale, $request->validated());

        AuditLogger::log('tab.closed', ['sale_id' => $sale->id, 'payment_method' => $sale->payment_method]);

        return new SaleResource($sale);
    }

    public function destroy(Request $request, Sale $sale): Response
    {
        AuditLogger::log('tab.cancelled', ['sale_id' => $sale->id]);

        $this->openTabService->cancelOpenTab($request->user(), $sale);

        return response()->noContent();
    }
}
