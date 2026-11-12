<?php

namespace App\Capabilities\Inventory;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\StockUrgency;

/**
 * Tool: inventario_reposicion. Que hay que comprar, ordenado por urgencia real.
 *
 * "Urgencia real" es cobertura (stock / venta diaria), no cercania al minimo:
 * 3 unidades de algo que se vende 10 veces al dia es mas urgente que 1 unidad
 * de algo que se vende una vez al mes.
 */
class RestockSuggestionsCapability implements Capability
{
    use CapsRows;

    public function requiredPermission(): ?string
    {
        return 'inventory.view';
    }

    public function requiredFeature(): ?string
    {
        return 'inventory';
    }

    public function rules(): array
    {
        return [
            'dias_analisis' => ['sometimes', 'nullable', 'integer', 'min:7', 'max:90'],
            'limite' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $days = (int) ($arguments['dias_analisis'] ?? 30);
        $limit = min((int) ($arguments['limite'] ?? 15), self::MAX_ROWS);

        // TODO el catalogo controlado, no los primeros 100: el recorte va
        // DESPUES de ordenar por urgencia (antes se cortaba antes y en un
        // catalogo grande lo urgente podia quedar afuera).
        $products = Product::query()
            ->where('is_active', true)
            ->where('track_stock', true)
            // Los productos de venta unica (combos puntuales, servicios) no se
            // reponen: incluirlos ensucia la recomendacion de compra.
            ->where('is_single_sale', false)
            ->withBranchStock()
            ->with(['variants' => fn ($q) => $q->where('is_active', true)->with('attributeValues')->withBranchStock()])
            ->get(['id', 'name', 'sku', 'stock', 'low_stock_alert_threshold']);

        if ($products->isEmpty()) {
            return ['ventana_dias' => $days, 'productos' => [], 'nota' => 'No hay productos con stock controlado.'];
        }

        // Un producto con tallas/colores se repone por variacion: su stock
        // vive en cada una y el del producto padre no significa nada (antes
        // salia "sin stock" un producto con todas sus tallas llenas).
        $simple = $products->filter(fn (Product $p) => $p->variants->isEmpty());
        $variants = $products->flatMap(fn (Product $p) => $p->variants->map(fn (ProductVariant $v) => [$p, $v]));

        $productVelocities = StockUrgency::productsVelocityBatch($business->id, $simple->pluck('id')->all(), $days);
        $variantVelocities = StockUrgency::productVariantsVelocityBatch(
            $business->id,
            $variants->map(fn ($pair) => $pair[1]->id)->all(),
            $days,
        );

        // El umbral es por producto y, si no esta definido, cae al del negocio.
        // Mismo criterio que las alertas de stock bajo por correo, para que el
        // chat no le diga "vas bien" a algo que el POS marca en rojo.
        $businessThreshold = max(0, (int) ($business->low_stock_alert_threshold ?? 5));

        $row = function (string $name, ?string $sku, float $stock, float $daily, ?int $ownThreshold) use ($businessThreshold) {
            $threshold = $ownThreshold !== null ? max(0, $ownThreshold) : $businessThreshold;

            return [
                'stock' => $stock, // clave que StockUrgency::sortByUrgency usa como desempate
                'nombre' => $name,
                'sku' => $sku,
                'stock_actual' => round($stock, 2),
                'umbral_stock_bajo' => $threshold,
                'venta_diaria_promedio' => round($daily, 2),
                'coverage_days' => StockUrgency::coverageDays($stock, $daily),
                'bajo_umbral' => $stock <= $threshold,
                // El POS permite vender sin existencias registradas, asi que el
                // stock puede quedar negativo. Se marca explicito para que el
                // modelo no lo interprete como demanda altisima y recomiende
                // comprar de urgencia algo que quiza solo esta mal inventariado.
                'stock_inconsistente' => $stock < 0,
            ];
        };

        $rows = $simple->map(fn (Product $p) => $row(
            (string) $p->name,
            $p->sku,
            $p->stockAt(),
            $productVelocities[$p->id] ?? 0.0,
            $p->low_stock_alert_threshold !== null ? (int) $p->low_stock_alert_threshold : null,
        ))->concat($variants->map(fn ($pair) => $row(
            $pair[0]->name.' · '.($pair[1]->attributeValues->pluck('value')->join(' / ') ?: $pair[1]->sku),
            $pair[1]->sku,
            $pair[1]->stockAt(),
            $variantVelocities[$pair[1]->id] ?? 0.0,
            $pair[1]->low_stock_alert_threshold !== null ? (int) $pair[1]->low_stock_alert_threshold
                : ($pair[0]->low_stock_alert_threshold !== null ? (int) $pair[0]->low_stock_alert_threshold : null),
        )))->values();

        $sorted = StockUrgency::sortByUrgency($rows)->take($limit)->map(fn (array $row) => [
            'nombre' => $row['nombre'],
            'sku' => $row['sku'],
            'stock_actual' => $row['stock_actual'],
            'umbral_stock_bajo' => $row['umbral_stock_bajo'],
            'venta_diaria_promedio' => $row['venta_diaria_promedio'],
            'dias_cobertura' => $row['coverage_days'],
            'bajo_umbral' => $row['bajo_umbral'],
            'stock_inconsistente' => $row['stock_inconsistente'],
        ])->values()->all();

        return [
            'ventana_dias' => $days,
            'productos' => $sorted,
            'nota' => 'dias_cobertura en null significa que no se vendio nada en la ventana '
                .'analizada, asi que no hay con que estimar cuanto durara el stock.',
        ];
    }
}
