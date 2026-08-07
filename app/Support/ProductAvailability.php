<?php

namespace App\Support;

use App\Models\Product;

/**
 * Calcula el stock realmente vendible de un producto: para uno con receta
 * (ingredientes) y el feature activo, products.stock es una columna
 * "fantasma" que nunca se decrementa - la disponibilidad real sale del
 * insumo mas escaso de la receta.
 */
class ProductAvailability
{
    public static function effectiveStock(Product $product, bool $ingredientsEnabled): float
    {
        if (! $product->track_stock) {
            return INF;
        }

        $effectiveStock = (float) $product->stock;

        if ($ingredientsEnabled && $product->ingredients->isNotEmpty()) {
            $possibleUnits = self::calculateRecipeUnits($product);
            if (is_finite($possibleUnits)) {
                $effectiveStock = max(0.0, $possibleUnits);
            }
        }

        return $effectiveStock;
    }

    private static function calculateRecipeUnits(Product $product): float
    {
        return (float) $product->ingredients
            ->map(function ($ingredient) {
                $required = (float) ($ingredient->pivot->quantity ?? 0);
                if ($required <= 0) {
                    return INF;
                }

                return floor(((float) $ingredient->stock) / $required);
            })
            ->min();
    }
}
