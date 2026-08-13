<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Dos responsabilidades relacionadas, igual que en legacy (misma clase
 * allá también):
 *
 * 1. effectiveStock(): stock realmente vendible de UN producto - para uno
 *    con receta (ingredientes) y el feature activo, products.stock es una
 *    columna "fantasma" que nunca se decrementa; la disponibilidad real
 *    sale del insumo más escaso de la receta.
 *
 * 2. forBusiness(): el catálogo COMPLETO de productos vendibles de un
 *    negocio, para Vender - a diferencia de ProductController::index()
 *    (paginado, para Catálogo/Compras/edición masiva), Vender filtra/busca
 *    en el cliente sin ida y vuelta por cada tecla, así que necesita todo
 *    el catálogo activo de una sola vez, no una página de 200 (con más de
 *    200 productos, los que caen después en el orden alfabético - ej. los
 *    que empiezan por "Z" - nunca llegaban al navegador). Cache de 10 min
 *    por negocio, salteado si el negocio tiene el feature 'ingredients'
 *    (el stock efectivo cambia con cada movimiento de ingrediente, cachear
 *    ahí dejaría cifras viejas). Invalidado desde Product::booted()
 *    (guardar/eliminar un producto) y desde StockMovement::applyToProduct()
 *    (el incremento SQL directo del stock no dispara el evento saved de
 *    Product).
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

    public static function cacheKey(int $businessId): string
    {
        return "pos_products_{$businessId}";
    }

    public static function clearCache(int $businessId): void
    {
        Cache::forget(self::cacheKey($businessId));
    }

    /** @return Collection<int, Product> */
    public static function forBusiness(Business $business): Collection
    {
        $ingredientsEnabled = (bool) $business->hasFeature('ingredients');

        if (! $ingredientsEnabled) {
            return Cache::remember(
                self::cacheKey($business->id),
                now()->addMinutes(10),
                fn () => self::fetchSellableProducts($business, $ingredientsEnabled)
            );
        }

        return self::fetchSellableProducts($business, $ingredientsEnabled);
    }

    /** @return Collection<int, Product> */
    private static function fetchSellableProducts(Business $business, bool $ingredientsEnabled): Collection
    {
        return Product::where('business_id', $business->id)
            ->where('is_active', true)
            ->with('category')
            ->when($ingredientsEnabled, fn ($q) => $q->with('ingredients'))
            ->orderBy('name')
            ->get();
    }
}
