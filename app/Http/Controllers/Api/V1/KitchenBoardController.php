<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateKitchenStatusRequest;
use App\Http\Resources\Api\V1\KitchenTicketResource;
use App\Models\Sale;
use App\Services\KitchenBoardService;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class KitchenBoardController extends Controller
{
    public function __construct(private KitchenBoardService $kitchenBoard) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tickets = $this->kitchenBoard->openTickets((int) $request->user()->business_id);

        return KitchenTicketResource::collection($tickets);
    }

    public function updateStatus(UpdateKitchenStatusRequest $request, Sale $sale): KitchenTicketResource
    {
        $saleItemIds = array_map('intval', $request->validated('sale_item_ids', []));

        $this->kitchenBoard->updateStatus($sale, $request->validated('kitchen_status'), $saleItemIds);

        AuditLogger::log('kitchen.status_changed', [
            'sale_id' => $sale->id,
            'status' => $request->validated('kitchen_status'),
            'sale_item_ids' => $saleItemIds,
        ]);

        return new KitchenTicketResource($sale->fresh(['items.product', 'user', 'table']));
    }
}
