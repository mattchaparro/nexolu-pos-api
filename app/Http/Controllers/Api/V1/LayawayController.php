<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\SendsReceipts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLayawayPaymentRequest;
use App\Http\Requests\Api\V1\StoreLayawayRequest;
use App\Http\Requests\Api\V1\UpdateLayawayItemsRequest;
use App\Http\Resources\Api\V1\LayawayResource;
use App\Models\Layaway;
use App\Services\LayawayService;
use App\Services\ReceiptPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LayawayController extends Controller
{
    use SendsReceipts;

    public function __construct(private LayawayService $layawayService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Layaway::with(['items.product', 'items.productVariant.attributeValues.productAttribute', 'payments'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $term = '%'.trim((string) $request->input('search')).'%';
            $query->where(function ($sub) use ($term) {
                $sub->where('customer_name', 'like', $term)
                    ->orWhere('customer_phone', 'like', $term);
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->string('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->string('date_to'));
        }

        return LayawayResource::collection($query->paginate(15)->withQueryString());
    }

    public function store(StoreLayawayRequest $request): LayawayResource
    {
        $layaway = $this->layawayService->create($request->user(), $request->validated());

        return new LayawayResource($layaway);
    }

    public function show(Layaway $layaway): LayawayResource
    {
        return new LayawayResource($layaway->load('items.product', 'items.productVariant.attributeValues.productAttribute', 'payments'));
    }

    public function storePayment(StoreLayawayPaymentRequest $request, Layaway $layaway): LayawayResource
    {
        $this->layawayService->addPayment($request->user(), $layaway, $request->validated());

        return new LayawayResource($layaway->fresh()->load('items.product', 'items.productVariant.attributeValues.productAttribute', 'payments'));
    }

    public function updateItems(UpdateLayawayItemsRequest $request, Layaway $layaway): LayawayResource
    {
        $layaway = $this->layawayService->updateItems($request->user(), $layaway, $request->validated('items'));

        return new LayawayResource($layaway);
    }

    public function complete(Layaway $layaway): LayawayResource
    {
        $this->layawayService->complete($layaway);

        return new LayawayResource($layaway->fresh()->load('items.product', 'items.productVariant.attributeValues.productAttribute', 'payments'));
    }

    public function cancel(Request $request, Layaway $layaway): Response
    {
        $this->layawayService->cancel($request->user(), $layaway);

        return response()->noContent();
    }

    public function receipt(Layaway $layaway): Response
    {
        return $this->receiptDownloadResponse(app(ReceiptPdfService::class)->forLayaway($layaway));
    }

    public function printReceipt(Request $request, Layaway $layaway): Response
    {
        return $this->receiptPrintResponse(app(ReceiptPdfService::class)->printLayaway($layaway, $request->boolean('auto_print', true)));
    }

    public function sendReceipt(Request $request, Layaway $layaway): JsonResponse
    {
        return $this->receiptSendResponse($request, 'layaway', $layaway->id, 'Comprobante de apartado');
    }
}
