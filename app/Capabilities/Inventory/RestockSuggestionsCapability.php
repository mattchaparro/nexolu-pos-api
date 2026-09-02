<?php

namespace App\Capabilities\Inventory;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Models\Business;
use App\Models\Product;
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

        $products = Product::query()
            ->where('is_active', true)
            ->where('track_stock', true)
            // Los productos de venta unica (combos puntuales, servicios) no se
            // reponen: incluirlos ensucia la recomendacion de compra.
            ->where('is_single_sale', false)
            ->limit(self::MAX_ROWS)
            ->get(['id', 'name', 'sku', 'stock', 'low_stock_alert_threshold']);

        if ($products->isEmpty()) {
            return ['ventana_dias' => $days, 'productos' => [], 'nota' => 'No hay productos con stock controlado.'];
        }

        $velocities = StockUrgency::productsVelocityBatch($business->id, $products->pluck('id')->all(), $days);

        // El umbral es por producto y, si no esta definido, cae al del negocio.
        // Mismo criterio que las alertas de stock bajo por correo, para que el
        // chat no le diga "vas bien" a algo que el POS marca en rojo.
        $businessThreshold = max(0, (int) ($business->low_stock_alert_threshold ?? 5));

        $rows = $products->map(function (Product $product) use ($velocities, $businessThreshold) {
            $stock = (float) $product->stock;
            $daily = $velocities[$product->id] ?? 0.0;
            $threshold = $product->low_stock_alert_threshold !== null
                ? max(0, (int) $product->low_stock_alert_threshold)
                : $businessThreshold;

            return [
                'producto_id' => $product->id,
                'stock' => $stock, // clave que StockUrgency::sortByUrgency usa como desempate
                'nombre' => (string) $product->name,
                'sku' => $product->sku,
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
        });

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
