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
                ->with(['items.product', 'partialPayments', 'table'])
                ->latest()
                ->get()
        );
    }

    public function store(StoreOpenTabRequest $request): SaleResource
    {
        $sale = $this->openTabService->openTab($request->user(), $request->validated());

        return new SaleResource($sale);
    }

    public function show(Sale $sale): SaleResource
    {
        return new SaleResource($sale->load('items.product', 'partialPayments', 'paymentSplits', 'table'));
    }

    public function addItems(OpenTabItemsRequest $request, Sale $sale): SaleResource
    {
        $sale = $this->openTabService->addItems($request->user(), $sale, $request->validated('items'));

        return new SaleResource($sale);
    }

    public function syncItems(OpenTabItemsRequest $request, Sale $sale): SaleResource
    {
        $sale = $this->openTabService->syncItems($request->user(), $sale, $request->validated('items'));

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

        return new SaleResource($sale->load('items.product', 'partialPayments', 'paymentSplits'));
    }

    public function close(CloseOpenTabRequest $request, Sale $sale): SaleResource
    {
        $sale = $this->openTabService->close($request->user(), $sale, $request->validated());

        return new SaleResource($sale);
    }

    public function destroy(Request $request, Sale $sale): Response
    {
        $this->openTabService->cancelOpenTab($request->user(), $sale);

        return response()->noContent();
    }
}
