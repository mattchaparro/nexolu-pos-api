<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreIngredientStockMovementRequest;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class IngredientStockMovementController extends Controller
{
    public function __construct(private StockService $stockService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StockMovement::whereNotNull('ingredient_id')->with(['reason', 'user'])->latest();

        if ($request->filled('ingredient_id')) {
            $query->where('ingredient_id', $request->integer('ingredient_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        return StockMovementResource::collection($query->paginate(20)->withQueryString());
    }

    public function store(StoreIngredientStockMovementRequest $request): StockMovementResource
    {
        $data = $request->validated();
        $ingredient = Ingredient::findOrFail($data['ingredient_id']);
        $user = $request->user();

        $movement = match ($data['type']) {
            StockMovement::TYPE_ENTRY => $this->stockService->ingredientEntry(
                $user, $ingredient, (float) $data['quantity'], $data['notes'] ?? null,
                $data['stock_movement_reason_id'] ?? null, $data['unit_cost_cop'] ?? null
            ),
            StockMovement::TYPE_EXIT => $this->stockService->ingredientExit(
                $user, $ingredient, (float) $data['quantity'], $data['notes'] ?? null,
                $data['stock_movement_reason_id'] ?? null, $data['unit_cost_cop'] ?? null
            ),
            StockMovement::TYPE_ADJUSTMENT => $this->stockService->ingredientAdjust(
                $user, $ingredient, (float) $data['quantity'], $data['notes'] ?? null,
                $data['stock_movement_reason_id'] ?? null
            ),
            default => throw ValidationException::withMessages(['type' => 'Tipo de movimiento no valido.']),
        };

        return new StockMovementResource($movement->load(['reason', 'user']));
    }
}
