<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DeliveryResource;
use App\Models\Sale;
use App\Services\DeliveryService;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeliveryController extends Controller
{
    public function __construct(private DeliveryService $deliveries) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return DeliveryResource::collection($this->deliveries->board((int) $request->user()->business_id));
    }

    public function take(Request $request, Sale $sale): DeliveryResource
    {
        $sale = $this->deliveries->take($request->user(), $sale);
        AuditLogger::log('delivery.taken', ['sale_id' => $sale->id]);

        return new DeliveryResource($sale->fresh(['items.product', 'courier', 'onlineOrder']));
    }

    public function deliver(Request $request, Sale $sale): DeliveryResource
    {
        $sale = $this->deliveries->deliver($request->user(), $sale);
        AuditLogger::log('delivery.delivered', ['sale_id' => $sale->id]);

        return new DeliveryResource($sale->fresh(['items.product', 'courier', 'onlineOrder']));
    }
}
