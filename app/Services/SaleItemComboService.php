<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\StockMovementReason;
use App\Models\User;
use App\Support\ProductAvailability;
use Illuminate\Validation\ValidationException;

/**
 * Combos: un producto armado con otros productos e insumos. El combo no
 * lleva stock propio; al venderlo se descuenta cada pieza (cantidad por
 * combo x cantidad de la línea) y la foto de lo descontado queda en
 * sale_item_components para devolverlo en reverso, cancelación o edición.
 */
class SaleItemComboService
{
    public function __construct(private StockService $stockService) {}

    public function isCombo(Product $product): bool
    {
        return $product->business->hasFeature('product_options') && $product->components()->exists();
    }

    /** @throws ValidationException */
    public function assertAvailable(Product $product, int $lineQuantity): void
    {
        $business = $product->business;
        $ingredientsEnabled = $business->hasFeature('ingredients');
        $variantsEnabled = $business->hasFeature('variants');

        foreach ($product->components()->get() as $component) {
            $needed = (float) $component->quantity * $lineQuantity;

            if ($component->component_product_id) {
                $piece = Product::with($ingredientsEnabled ? 'ingredients' : [])->find($component->component_product_id);
                $available = $piece ? ProductAvailability::effectiveStock($piece, $ingredientsEnabled, $variantsEnabled) : 0.0;
                $name = $piece?->name ?? 'un producto del combo';
            } else {
                $piece = Ingredient::find($component->ingredient_id);
                $available = $piece ? $piece->stockAt() : 0.0;
                $name = $piece?->name ?? 'un insumo del combo';
            }

            if ($needed > $available) {
                throw ValidationException::withMessages([
                    'items' => 'No hay stock suficiente de «'.$name.'» para el combo «'.$product->name.'».',
                ]);
            }
        }
    }

    /** Guarda la foto de las piezas y las descuenta del inventario. */
    public function attach(User $user, Sale $sale, SaleItem $item, Product $product): void
    {
        foreach ($product->components()->with('componentProduct', 'ingredient')->get() as $component) {
            $item->components()->create([
                'component_product_id' => $component->component_product_id,
                'ingredient_id' => $component->ingredient_id,
                'name' => $component->componentProduct?->name ?? $component->ingredient?->name ?? 'Pieza',
                'quantity' => $component->quantity,
            ]);
        }

        $this->move($user, $sale, $item, StockMovement::TYPE_EXIT);
    }

    /** Devuelve al inventario lo que descontó la línea. */
    public function restore(User $user, Sale $sale, SaleItem $item, ?string $notes = null): void
    {
        $this->move($user, $sale, $item, StockMovement::TYPE_ENTRY, $notes);
    }

    private function move(User $user, Sale $sale, SaleItem $item, string $type, ?string $notes = null): void
    {
        $isExit = $type === StockMovement::TYPE_EXIT;

        foreach ($item->components()->get() as $piece) {
            $quantity = (float) $piece->quantity * (int) $item->quantity;

            if ($piece->ingredient_id) {
                StockMovement::create([
                    'ingredient_id' => $piece->ingredient_id,
                    'business_id' => $sale->business_id,
                    'type' => $type,
                    'stock_movement_reason_id' => StockMovementReason::systemIdForCode($isExit ? StockMovementReason::CODE_SALE : StockMovementReason::CODE_SALE_REVERSAL),
                    'quantity' => ($isExit ? -1 : 1) * abs($quantity),
                    'reference' => $isExit ? "Venta #{$sale->id}" : "Ajuste venta #{$sale->id}",
                    'notes' => $notes,
                    'user_id' => $user->id,
                ]);

                continue;
            }

            $product = $piece->component_product_id ? Product::with('ingredients')->find($piece->component_product_id) : null;
            if (! $product) {
                continue;
            }

            $units = (int) round($quantity);
            if ($isExit) {
                $this->stockService->registerSale($user, $product, $units, $sale);
                $this->stockService->registerIngredientsConsumption($user, $product, $units, $sale);
            } else {
                $this->stockService->registerSaleReversal($user, $product, $units, $sale, $notes);
                $this->stockService->restoreIngredientsConsumption($user, $product, $units, $sale, $notes);
            }
        }
    }
}
