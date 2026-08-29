<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreStockMovementRequest;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
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

        // El historial de UNA variante. Sin esto, filtrar por product_id
        // devuelve mezclados los movimientos de todas sus variantes, que es
        // justo lo que no sirve para revisar una talla concreta.
        if ($request->filled('product_variant_id')) {
            $query->where('product_variant_id', $request->integer('product_variant_id'));
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

        if (! empty($data['product_variant_id'])) {
            return new StockMovementResource(
                $this->storeForVariant($user, $product, $data)->load(['reason', 'user'])
            );
        }

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

    /**
     * Movimiento manual de una variante concreta. La variante tiene que ser
     * del producto que viene en el payload: ambos estan escopeados al
     * negocio por separado, pero sin este cruce se podria mover el stock de
     * la variante de OTRO producto del mismo negocio.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeForVariant(User $user, Product $product, array $data): StockMovement
    {
        $variant = ProductVariant::where('product_id', $product->id)
            ->findOr((int) $data['product_variant_id'], fn () => throw ValidationException::withMessages([
                'product_variant_id' => 'Esa variante no pertenece a este producto.',
            ]));

        return match ($data['type']) {
            StockMovement::TYPE_ENTRY => $this->stockService->variantEntry(
                $user, $variant, (float) $data['quantity'], $data['notes'] ?? null,
                $data['stock_movement_reason_id'] ?? null, $data['unit_cost_cop'] ?? null
            ),
            StockMovement::TYPE_EXIT => $this->stockService->variantExit(
                $user, $variant, (float) $data['quantity'], $data['notes'] ?? null,
                $data['stock_movement_reason_id'] ?? null, $data['unit_cost_cop'] ?? null
            ),
            StockMovement::TYPE_ADJUSTMENT => $this->stockService->variantAdjust(
                $user, $variant, (float) $data['quantity'], $data['notes'] ?? null,
                $data['stock_movement_reason_id'] ?? null
            ),
            default => throw ValidationException::withMessages(['type' => 'Tipo de movimiento no valido.']),
        };
    }
}
