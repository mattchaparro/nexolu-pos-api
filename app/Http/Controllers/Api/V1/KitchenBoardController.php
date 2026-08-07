<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateKitchenStatusRequest;
use App\Http\Resources\Api\V1\KitchenTicketResource;
use App\Models\Sale;
use App\Services\KitchenBoardService;
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
        $this->kitchenBoard->updateStatus(
            $sale,
            $request->validated('kitchen_status'),
            array_map('intval', $request->validated('sale_item_ids', [])),
        );

        return new KitchenTicketResource($sale->fresh(['items.product', 'user', 'table']));
    }
}
