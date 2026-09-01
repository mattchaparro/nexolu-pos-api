<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreStockTransferRequest;
use App\Http\Resources\Api\V1\StockTransferResource;
use App\Models\Branch;
use App\Models\StockTransfer;
use App\Services\StockTransferService;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockTransferController extends Controller
{
    public function __construct(private StockTransferService $transfers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StockTransfer::with(['fromBranch', 'toBranch', 'user'])->latest('transferred_at')->latest('id');

        // Con una sede activa se muestran sus traslados en las dos
        // direcciones: lo que mando y lo que le llego. Filtrar solo por
        // origen dejaria a la sede receptora sin ver de donde salio lo que
        // le entro al inventario.
        if ($branchId = BranchContext::branchId()) {
            $query->involvingBranch($branchId);
        }

        if ($request->filled('from_branch_id')) {
            $query->where('from_branch_id', $request->integer('from_branch_id'));
        }

        if ($request->filled('to_branch_id')) {
            $query->where('to_branch_id', $request->integer('to_branch_id'));
        }

        return StockTransferResource::collection($query->paginate(20)->withQueryString());
    }

    public function store(StoreStockTransferRequest $request): StockTransferResource
    {
        $data = $request->validated();

        $transfer = $this->transfers->transfer(
            $request->user(),
            Branch::findOrFail($data['from_branch_id']),
            Branch::findOrFail($data['to_branch_id']),
            $data['items'],
            $data['notes'] ?? null,
        );

        return new StockTransferResource(
            $transfer->load(['fromBranch', 'toBranch', 'user', 'items.product', 'items.productVariant', 'items.ingredient'])
        );
    }

    public function show(StockTransfer $stockTransfer): StockTransferResource
    {
        return new StockTransferResource(
            $stockTransfer->load(['fromBranch', 'toBranch', 'user', 'items.product', 'items.productVariant', 'items.ingredient'])
        );
    }
}
