<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreStockMovementRequest;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class StockMovementController extends Controller
{
    public function __construct(private StockService $stockService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StockMovement::whereNotNull('product_id')->with(['reason', 'user'])->latest();

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        return StockMovementResource::collection($query->paginate(20)->withQueryString());
    }

    public function store(StoreStockMovementRequest $request): StockMovementResource
    {
        $data = $request->validated();
        $product = Product::findOrFail($data['product_id']);
        $user = $request->user();

        $movement = match ($data['type']) {
            StockMovement::TYPE_ENTRY => $this->stockService->entry(
                $user, $product, (float) $data['quantity'], $data['notes'] ?? null,
                $data['stock_movement_reason_id'] ?? null, $data['unit_cost_cop'] ?? null
            ),
            StockMovement::TYPE_EXIT => $this->stockService->exit(
                $user, $product, (float) $data['quantity'], $data['notes'] ?? null,
                $data['stock_movement_reason_id'] ?? null, $data['unit_cost_cop'] ?? null
            ),
            StockMovement::TYPE_ADJUSTMENT => $this->stockService->adjust(
                $user, $product, (float) $data['quantity'], $data['notes'] ?? null,
                $data['stock_movement_reason_id'] ?? null
            ),
            default => throw ValidationException::withMessages(['type' => 'Tipo de movimiento no valido.']),
        };

        return new StockMovementResource($movement->load(['reason', 'user']));
    }
}
