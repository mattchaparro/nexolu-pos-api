<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Que tan urgente es reponer un producto o ingrediente, medido por velocidad
 * REAL de venta/consumo -- no por que tan cerca esta del umbral configurado.
 *
 * Un producto con 1 unidad de stock que casi no se vende no es mas urgente
 * que uno con 5 unidades que vende 20 al dia. Ordenar solo por cercania al
 * umbral llena la alerta de productos que nadie ajusto nunca y la vuelve
 * spam que se deja de revisar. Ver LowStockAlertReport y
 * DailyBusinessSummaryService, que comparten esta misma nocion de "dias de
 * cobertura" para que signifique lo mismo en ambos lugares.
 */
class StockUrgency
{
    public const DEFAULT_WINDOW_DAYS = 30;

    public static function productVelocity(int $businessId, int $productId, int $days = self::DEFAULT_WINDOW_DAYS): float
    {
        $sold = abs((float) DB::table('stock_movements')
            ->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->where('type', 'sale')
            ->where('created_at', '>=', now()->subDays($days))
            ->sum('quantity'));

        return $days > 0 ? $sold / $days : 0.0;
    }

    /**
     * Un ingrediente no se vende directo -- se descuenta cuando se vende un
     * producto que lo usa (tabla ingredient_product), asi que la velocidad se
     * deriva de sale_items cruzado con la receta.
     */
    public static function ingredientVelocity(int $businessId, int $ingredientId, int $days = self::DEFAULT_WINDOW_DAYS): float
    {
        $consumed = (float) (DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('ingredient_product', 'ingredient_product.product_id', '=', 'sale_items.product_id')
            ->where('sales.business_id', $businessId)
            ->where('sales.status', 'closed')
            ->where('sales.is_non_revenue', false)
            ->where('sales.is_credit', false)
            ->where('ingredient_product.ingredient_id', $ingredientId)
            ->where('sales.created_at', '>=', now()->subDays($days))
            ->selectRaw('SUM(sale_items.quantity * ingredient_product.quantity) as total')
            ->value('total') ?? 0);

        return $days > 0 ? $consumed / $days : 0.0;
    }

    /**
     * Velocidad diaria de MUCHOS productos en una sola query (evita N+1 en
     * negocios con cientos de productos bajo el umbral).
     *
     * @param  list<int>  $productIds
     * @return array<int, float> product_id => unidades/dia
     */
    public static function productsVelocityBatch(int $businessId, array $productIds, int $days = self::DEFAULT_WINDOW_DAYS): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('stock_movements')
            ->where('business_id', $businessId)
            ->where('type', 'sale')
            ->whereIn('product_id', $productIds)
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as total')
            ->pluck('total', 'product_id');

        return $rows->map(fn ($total) => $days > 0 ? abs((float) $total) / $days : 0.0)->all();
    }

    /**
     * Velocidad diaria de MUCHOS ingredientes en una sola query.
     *
     * @param  list<int>  $ingredientIds
     * @return array<int, float> ingredient_id => unidades/dia
     */
    public static function ingredientsVelocityBatch(int $businessId, array $ingredientIds, int $days = self::DEFAULT_WINDOW_DAYS): array
    {
        if ($ingredientIds === []) {
            return [];
        }

        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('ingredient_product', 'ingredient_product.product_id', '=', 'sale_items.product_id')
            ->where('sales.business_id', $businessId)
            ->where('sales.status', 'closed')
            ->where('sales.is_non_revenue', false)
            ->where('sales.is_credit', false)
            ->whereIn('ingredient_product.ingredient_id', $ingredientIds)
            ->where('sales.created_at', '>=', now()->subDays($days))
            ->groupBy('ingredient_product.ingredient_id')
            ->selectRaw('ingredient_product.ingredient_id as ingredient_id, SUM(sale_items.quantity * ingredient_product.quantity) as total')
            ->pluck('total', 'ingredient_id');

        return $rows->map(fn ($total) => $days > 0 ? (float) $total / $days : 0.0)->all();
    }

    /**
     * Dias de cobertura al ritmo actual, o null si no hay movimiento reciente
     * del cual estimar un ritmo. Null NO significa "no urgente" -- significa
     * "no sabemos si importa", que es justo la distincion que hace falta para
     * no tratarlo como si fuera lo mismo que un producto que si se esta
     * agotando de verdad (catalogos con cientos de referencias que casi no
     * rotan no deben ganarle el puesto de "mas urgente" al que si vende).
     */
    public static function coverageDays(float $stock, float $dailyVelocity): ?float
    {
        if ($dailyVelocity <= 0) {
            return null;
        }

        if ($stock <= 0) {
            return 0.0;
        }

        return round($stock / $dailyVelocity, 1);
    }

    /**
     * Reordena una coleccion de items (cada uno con clave 'coverage_days',
     * float|null) dejando primero los de cobertura mas corta -- los
     * genuinamente urgentes -- y al final, en su propio grupo, los que no
     * tienen movimiento reciente. Dentro de cada grupo, 'stock' es el
     * desempate.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    public static function sortByUrgency(Collection $items): Collection
    {
        $withMovement = $items->filter(fn (array $i) => $i['coverage_days'] !== null)
            ->sortBy('coverage_days');

        $withoutMovement = $items->filter(fn (array $i) => $i['coverage_days'] === null)
            ->sortBy('stock');

        return $withMovement->concat($withoutMovement)->values();
    }
}
