<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Version simplificada del LowStockAlert de legacy: sin velocidad de venta -
 * ordena por cuanto stock queda bajo el umbral en vez de por dias de
 * cobertura. Cuando el modulo de reportes/urgencia se porte, esto se puede
 * alinear con el calculo completo de legacy (StockUrgency).
 */
class LowStockAlertReport
{
    /**
     * @return array{count: int, items: Collection<int, array{kind: string, id: int, name: string, stock: float, threshold: int, unit: ?string}>}
     */
    public static function forBusiness(Business $business): array
    {
        $defaultThreshold = max(0, (int) ($business->low_stock_alert_threshold ?? 5));
        $ingredientsEnabled = $business->hasFeature('ingredients');

        $items = self::lowProducts($business, $defaultThreshold, $ingredientsEnabled)
            ->concat($ingredientsEnabled ? self::lowIngredients($business, $defaultThreshold) : [])
            ->sortBy('stock')
            ->values();

        return [
            'count' => $items->count(),
            'items' => $items,
        ];
    }

    /**
     * @return Collection<int, array{kind: string, id: int, name: string, stock: float, threshold: int, unit: ?string}>
     */
    private static function lowProducts(Business $business, int $defaultThreshold, bool $ingredientsEnabled): Collection
    {
        return Product::where('business_id', $business->id)
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where('is_single_sale', false)
            ->when($ingredientsEnabled, fn ($query) => $query->with('ingredients:id,stock'))
            ->get(['id', 'name', 'stock', 'track_stock', 'low_stock_alert_threshold'])
            ->map(function (Product $product) use ($defaultThreshold, $ingredientsEnabled) {
                $threshold = $product->low_stock_alert_threshold !== null
                    ? max(0, (int) $product->low_stock_alert_threshold)
                    : $defaultThreshold;

                return [
                    'kind' => 'product',
                    'id' => $product->id,
                    'name' => $product->name,
                    'stock' => ProductAvailability::effectiveStock($product, $ingredientsEnabled),
                    'threshold' => $threshold,
                    'unit' => null,
                ];
            })
            ->filter(fn (array $item) => is_finite($item['stock']) && $item['stock'] <= $item['threshold']);
    }

    /**
     * @return Collection<int, array{kind: string, id: int, name: string, stock: float, threshold: int, unit: ?string}>
     */
    private static function lowIngredients(Business $business, int $defaultThreshold): Collection
    {
        return Ingredient::where('business_id', $business->id)
            ->where('is_active', true)
            ->get(['id', 'name', 'stock', 'min_stock', 'unit'])
            ->map(function (Ingredient $ingredient) use ($defaultThreshold) {
                $minStock = (float) $ingredient->min_stock;
                $threshold = $minStock > 0 ? (int) ceil($minStock) : $defaultThreshold;

                return [
                    'kind' => 'ingredient',
                    'id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'stock' => (float) $ingredient->stock,
                    'threshold' => $threshold,
                    'unit' => $ingredient->unit,
                ];
            })
            ->filter(fn (array $item) => $item['stock'] <= $item['threshold']);
    }
}
