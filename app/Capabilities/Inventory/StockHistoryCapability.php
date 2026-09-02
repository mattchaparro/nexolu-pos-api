<?php

namespace App\Capabilities\Inventory;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\NameMatcher;
use Carbon\Carbon;

/**
 * Tool: historial_stock. Quien movio el stock de algo y cuando.
 *
 * NO incluye las salidas por venta: son actividad rutinaria y ahogarian lo
 * unico que se esta buscando, que son los cambios manuales. La pregunta
 * detras de esta herramienta casi siempre es "quien me toco el inventario".
 */
class StockHistoryCapability implements Capability
{
    use CapsRows;

    private const DEFAULT_DAYS = 14;

    /** Cuantos items distintos se reportan si el nombre coincide con varios. */
    private const MAX_ITEMS = 5;

    private const MAX_MOVEMENTS_PER_ITEM = 20;

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
            'nombre' => ['required', 'string', 'max:200'],
            'dias' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $name = trim((string) $arguments['nombre']);
        $days = (int) ($arguments['dias'] ?? self::DEFAULT_DAYS);
        $since = now()->subDays($days);

        // A diferencia de las capacidades de escritura, aca la ambiguedad no
        // se corta: mostrar el historial de los tres productos que coinciden
        // no rompe nada y le ahorra al usuario una pregunta.
        $products = NameMatcher::filter(
            Product::where('is_active', true)->where('track_stock', true)->orderBy('name')->get(['id', 'name', 'stock']),
            $name,
            fn (Product $product) => (string) $product->name
        );

        $ingredients = $business->hasFeature('ingredients')
            ? NameMatcher::filter(
                Ingredient::where('is_active', true)->orderBy('name')->get(['id', 'name', 'stock']),
                $name,
                fn (Ingredient $ingredient) => (string) $ingredient->name
            )
            : [];

        if ($products === [] && $ingredients === []) {
            return [
                'resultados' => [],
                'mensaje' => "No hay ningun producto ni ingrediente que coincida con \"{$name}\".",
            ];
        }

        $results = [];

        foreach (array_slice($products, 0, self::MAX_ITEMS) as $product) {
            $results[] = $this->historyOf('producto', $product->name, (float) $product->stock, 'product_id', $product->id, $since);
        }

        foreach (array_slice($ingredients, 0, self::MAX_ITEMS - count($results)) as $ingredient) {
            $results[] = $this->historyOf('ingrediente', $ingredient->name, (float) $ingredient->stock, 'ingredient_id', $ingredient->id, $since);
        }

        return [
            'buscado' => $name,
            'dias_revisados' => $days,
            'resultados' => $results,
        ];
    }

    /** @return array<string, mixed> */
    private function historyOf(string $type, string $name, float $stock, string $column, int $id, Carbon $since): array
    {
        $movements = StockMovement::query()
            ->where($column, $id)
            // 'sale' y sus reversos quedan fuera a proposito: ver el docblock
            // de la clase.
            ->whereIn('type', ['entry', 'exit', 'adjustment'])
            ->where('created_at', '>=', $since)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(self::MAX_MOVEMENTS_PER_ITEM)
            ->get();

        return [
            'tipo' => $type,
            'nombre' => $name,
            'stock_actual' => $stock,
            'movimientos' => $movements->map(fn (StockMovement $movement) => [
                'fecha' => $movement->created_at->toIso8601String(),
                'tipo_movimiento' => match ($movement->type) {
                    'entry' => 'entrada',
                    'exit' => 'salida',
                    'adjustment' => 'ajuste',
                    default => $movement->type,
                },
                'cantidad' => (float) $movement->quantity,
                'usuario' => $movement->user?->name ?? 'usuario eliminado',
                'nota' => $movement->notes,
            ])->values()->all(),
        ];
    }
}
