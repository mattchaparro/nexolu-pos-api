<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Version simplificada del LowStockAlert de legacy: solo productos (el
 * modulo de ingredientes/recetas no existe todavia en esta API) y sin
 * velocidad de venta - ordena por cuanto stock queda bajo el umbral en vez
 * de por dias de cobertura. Cuando el modulo de ingredientes se porte, esto
 * se puede alinear con el calculo completo de legacy.
 */
class LowStockAlertReport
{
    /**
     * @return array{count: int, items: Collection<int, array{id: int, name: string, stock: float, threshold: int}>}
     */
    public static function forBusiness(Business $business): array
    {
        $defaultThreshold = max(0, (int) ($business->low_stock_alert_threshold ?? 5));

        $items = Product::where('business_id', $business->id)
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where('is_single_sale', false)
            ->get(['id', 'name', 'stock', 'low_stock_alert_threshold'])
            ->map(function (Product $product) use ($defaultThreshold) {
                $threshold = $product->low_stock_alert_threshold !== null
                    ? max(0, (int) $product->low_stock_alert_threshold)
                    : $defaultThreshold;

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'stock' => (float) $product->stock,
                    'threshold' => $threshold,
                ];
            })
            ->filter(fn (array $item) => $item['stock'] <= $item['threshold'])
            ->sortBy('stock')
            ->values();

        return [
            'count' => $items->count(),
            'items' => $items,
        ];
    }
}
