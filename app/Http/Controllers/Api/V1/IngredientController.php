<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreIngredientRequest;
use App\Http\Requests\Api\V1\UpdateIngredientRequest;
use App\Http\Resources\Api\V1\IngredientResource;
use App\Models\Ingredient;
use App\Services\IngredientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class IngredientController extends Controller
{
    public function __construct(private IngredientService $ingredientService) {}

    /**
     * Cards de resumen de la pestaña Ingredientes del Catalogo, sobre el
     * conjunto completo (no solo la pagina visible). Puerto de
     * Admin\InventoryController::buildIndexProps() del legacy.
     */
    public function summary(Request $request): JsonResponse
    {
        $lowStockThreshold = (float) ($request->user()?->business?->low_stock_alert_threshold ?? 5);

        $ingredients = Ingredient::get(['id', 'stock', 'min_stock', 'is_active']);

        $lowStockCount = $ingredients->filter(function (Ingredient $i) use ($lowStockThreshold) {
            $min = (float) ($i->min_stock ?? 0);
            $threshold = $min > 0 ? $min : $lowStockThreshold;

            return (float) $i->stock <= $threshold;
        })->count();

        return response()->json([
            'active_count' => $ingredients->where('is_active', true)->count(),
            'low_stock_count' => $lowStockCount,
        ]);
    }

    public function index(): AnonymousResourceCollection
    {
        // with('products:id,name'): puerto de "Platos que lo usan" del
        // legacy (Admin/Inventory/Index.vue) - se trae de una vez para
        // toda la pagina, no bajo demanda al abrir el modal.
        return IngredientResource::collection(
            Ingredient::with('products:id,name')->orderBy('name')->paginate()
        );
    }

    public function store(StoreIngredientRequest $request): IngredientResource
    {
        return new IngredientResource($this->ingredientService->create($request->validated()));
    }

    public function show(Ingredient $ingredient): IngredientResource
    {
        return new IngredientResource($ingredient->load('products:id,name'));
    }

    public function update(UpdateIngredientRequest $request, Ingredient $ingredient): IngredientResource
    {
        return new IngredientResource($this->ingredientService->update($ingredient, $request->validated()));
    }

    public function destroy(Ingredient $ingredient): Response
    {
        $ingredient->delete();

        return response()->noContent();
    }
}
