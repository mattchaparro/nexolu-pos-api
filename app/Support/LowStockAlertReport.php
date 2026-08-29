<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Ordena por urgencia real (StockUrgency: velocidad de venta/consumo, no
 * cercania cruda al umbral) para que "el mas urgente" signifique lo mismo
 * aqui, en el correo de alertas y en DailyBusinessSummaryService.
 */
class LowStockAlertReport
{
    /**
     * @return array{count: int, items: Collection<int, array{kind: string, id: int, name: string, stock: float, threshold: int, unit: ?string, coverage_days: ?float, is_recipe: bool}>}
     */
    public static function forBusiness(Business $business): array
    {
        $defaultThreshold = max(0, (int) ($business->low_stock_alert_threshold ?? 5));
        $ingredientsEnabled = $business->hasFeature('ingredients');
        $variantsEnabled = $business->hasFeature('variants');

        $items = self::lowProducts($business, $defaultThreshold, $ingredientsEnabled, $variantsEnabled)
            ->concat($ingredientsEnabled ? self::lowIngredients($business, $defaultThreshold) : [])
            ->pipe(fn (Collection $items) => StockUrgency::sortByUrgency($items));

        return [
            'count' => $items->count(),
            'items' => $items,
        ];
    }

    /**
     * @return Collection<int, array{kind: string, id: int, name: string, stock: float, threshold: int, unit: ?string, coverage_days: ?float, is_recipe: bool}>
     */
    private static function lowProducts(Business $business, int $defaultThreshold, bool $ingredientsEnabled, bool $variantsEnabled): Collection
    {
        $products = Product::where('business_id', $business->id)
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where('is_single_sale', false)
            ->when($ingredientsEnabled, fn ($query) => $query->with('ingredients:id,stock'))
            ->when($variantsEnabled, fn ($query) => $query->with('variants.attributeValues'))
            ->get(['id', 'name', 'stock', 'track_stock', 'low_stock_alert_threshold']);

        // Un producto con variantes se evalua variante por variante (ver
        // lowVariants()), nunca como un todo: products.stock queda
        // "fantasma" para el (siempre 0, ver ProductAvailability), asi que
        // ni el stock ni el umbral del producto significan nada aqui.
        $simpleProducts = $variantsEnabled ? $products->reject(fn (Product $p) => $p->hasVariants()) : $products;

        $belowThreshold = $simpleProducts
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
                    'is_recipe' => $ingredientsEnabled && $product->ingredients->isNotEmpty(),
                ];
            })
            ->filter(fn (array $item) => is_finite($item['stock']) && $item['stock'] <= $item['threshold'])
            ->values();

        $velocities = StockUrgency::productsVelocityBatch($business->id, $belowThreshold->pluck('id')->all());

        $belowThreshold = $belowThreshold->map(function (array $item) use ($velocities) {
            $item['coverage_days'] = StockUrgency::coverageDays($item['stock'], $velocities[$item['id']] ?? 0.0);

            return $item;
        });

        if (! $variantsEnabled) {
            return $belowThreshold;
        }

        return $belowThreshold->concat(
            self::lowVariants($business, $defaultThreshold, $products->filter(fn (Product $p) => $p->hasVariants()))
        );
    }

    /**
     * Contraparte de lowProducts() para productos con variantes (decision de
     * negocio de la Fase 1 de "productos con variaciones": low-stock POR
     * VARIANTE, no agregado al producto) - cada variante activa compara su
     * propio stock contra su propio umbral (o el del negocio si no tiene
     * uno propio), igual que un producto normal.
     *
     * @param  Collection<int, Product>  $variantProducts  productos con hasVariants()=true, con variants.attributeValues ya cargado
     * @return Collection<int, array{kind: string, id: int, name: string, stock: float, threshold: int, unit: ?string, coverage_days: ?float, is_recipe: bool}>
     */
    private static function lowVariants(Business $business, int $defaultThreshold, Collection $variantProducts): Collection
    {
        $belowThreshold = collect();

        foreach ($variantProducts as $product) {
            foreach ($product->variants->where('is_active', true) as $variant) {
                $threshold = $variant->low_stock_alert_threshold !== null
                    ? max(0, (int) $variant->low_stock_alert_threshold)
                    : $defaultThreshold;

                if ((float) $variant->stock > $threshold) {
                    continue;
                }

                $label = $variant->attributeValues->pluck('value')->implode(' / ');

                $belowThreshold->push([
                    'kind' => 'product_variant',
                    'id' => $variant->id,
                    'name' => $label !== '' ? "{$product->name} ({$label})" : $product->name,
                    'stock' => (float) $variant->stock,
                    'threshold' => $threshold,
                    'unit' => null,
                    'is_recipe' => false,
                ]);
            }
        }

        $velocities = StockUrgency::productVariantsVelocityBatch($business->id, $belowThreshold->pluck('id')->all());

        return $belowThreshold->map(function (array $item) use ($velocities) {
            $item['coverage_days'] = StockUrgency::coverageDays($item['stock'], $velocities[$item['id']] ?? 0.0);

            return $item;
        });
    }

    /**
     * @return Collection<int, array{kind: string, id: int, name: string, stock: float, threshold: int, unit: ?string, coverage_days: ?float, is_recipe: bool}>
     */
    private static function lowIngredients(Business $business, int $defaultThreshold): Collection
    {
        $belowThreshold = Ingredient::where('business_id', $business->id)
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
                    'is_recipe' => false,
                ];
            })
            ->filter(fn (array $item) => $item['stock'] <= $item['threshold'])
            ->values();

        $velocities = StockUrgency::ingredientsVelocityBatch($business->id, $belowThreshold->pluck('id')->all());

        return $belowThreshold->map(function (array $item) use ($velocities) {
            $item['coverage_days'] = StockUrgency::coverageDays($item['stock'], $velocities[$item['id']] ?? 0.0);

            return $item;
        });
    }
}
