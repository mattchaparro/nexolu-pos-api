<?php

namespace App\Capabilities\Inventory;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Models\Business;
use App\Models\Ingredient;
use App\Models\User;
use App\Support\StockUrgency;

/** Tool: ingredientes_stock. Insumos, cuanto queda y cuanto durara. */
class IngredientStockCapability implements Capability
{
    use CapsRows;

    public function requiredPermission(): ?string
    {
        return 'inventory.view';
    }

    public function requiredFeature(): ?string
    {
        return 'ingredients';
    }

    public function rules(): array
    {
        return [
            'solo_bajo_minimo' => ['sometimes', 'nullable', 'boolean'],
            'limite' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $query = Ingredient::query()->where('is_active', true);

        if (! empty($arguments['solo_bajo_minimo'])) {
            $query->whereColumn('stock', '<=', 'min_stock');
        }

        // El pool se acota en SQL para no traer un catalogo entero, pero el
        // orden final se decide en PHP: la urgencia depende de la velocidad de
        // consumo, que no sale del mismo query sin un join costoso por fila.
        $ingredients = $query->limit(self::MAX_ROWS)->get(['id', 'name', 'unit', 'stock', 'min_stock', 'cost_price']);

        if ($ingredients->isEmpty()) {
            return ['total_ingredientes' => 0, 'ingredientes' => []];
        }

        $velocities = StockUrgency::ingredientsVelocityBatch($business->id, $ingredients->pluck('id')->all());

        $rows = $ingredients->map(function (Ingredient $ingredient) use ($velocities) {
            $stock = (float) $ingredient->stock;

            return [
                'stock' => $stock, // desempate de StockUrgency::sortByUrgency
                'ingrediente' => (string) $ingredient->name,
                'unidad' => $ingredient->unit,
                'stock_actual' => round($stock, 3),
                'stock_minimo' => round((float) $ingredient->min_stock, 3),
                'bajo_minimo' => $stock <= (float) $ingredient->min_stock,
                'costo_unitario' => round((float) $ingredient->cost_price, 2),
                'valor_en_stock' => round($stock * (float) $ingredient->cost_price, 2),
                'coverage_days' => StockUrgency::coverageDays($stock, $velocities[$ingredient->id] ?? 0.0),
            ];
        });

        $limit = min((int) ($arguments['limite'] ?? 20), self::MAX_ROWS);

        return [
            'total_ingredientes' => $ingredients->count(),
            'bajo_minimo' => $rows->filter(fn (array $row) => $row['bajo_minimo'])->count(),
            'valor_total_inventario' => round($rows->sum('valor_en_stock'), 2),
            'ingredientes' => StockUrgency::sortByUrgency($rows)->take($limit)->map(fn (array $row) => [
                'ingrediente' => $row['ingrediente'],
                'unidad' => $row['unidad'],
                'stock_actual' => $row['stock_actual'],
                'stock_minimo' => $row['stock_minimo'],
                'bajo_minimo' => $row['bajo_minimo'],
                'costo_unitario' => $row['costo_unitario'],
                'valor_en_stock' => $row['valor_en_stock'],
                'dias_cobertura' => $row['coverage_days'],
            ])->values()->all(),
        ];
    }
}
