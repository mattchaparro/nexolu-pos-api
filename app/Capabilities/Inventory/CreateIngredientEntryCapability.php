<?php

namespace App\Capabilities\Inventory;

use App\Capabilities\Capability;
use App\Capabilities\Support\ResolvesProductByName;
use App\Models\Business;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Validation\ValidationException;

/**
 * Tool: crear_entrada_ingrediente (escritura). Entrada de materia prima.
 *
 * Igual que crear_entrada_inventario pero para insumos de receta. Si viene el
 * costo, StockService recalcula el promedio ponderado del ingrediente y lo
 * propaga a los productos que lo usan.
 */
class CreateIngredientEntryCapability implements Capability
{
    use ResolvesProductByName;

    public function __construct(private StockService $stockService) {}

    public function requiredPermission(): ?string
    {
        return 'inventory.add';
    }

    public function requiredFeature(): ?string
    {
        return 'ingredients';
    }

    public function rules(): array
    {
        return [
            'ingrediente' => ['required', 'string', 'max:200'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'nota' => ['sometimes', 'nullable', 'string', 'max:500'],
            'valor_total' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'valor_unitario' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $ingredient = $this->resolveIngredientByName((string) $arguments['ingrediente']);
        $quantity = (float) $arguments['cantidad'];

        $hasTotal = isset($arguments['valor_total']);
        $hasUnit = isset($arguments['valor_unitario']);

        if ($hasTotal && $hasUnit) {
            throw ValidationException::withMessages([
                'valor_total' => 'Da el valor total o el valor por unidad, no los dos.',
            ]);
        }

        $unitCost = match (true) {
            $hasUnit => (float) $arguments['valor_unitario'],
            $hasTotal && $quantity > 0 => (float) $arguments['valor_total'] / $quantity,
            default => null,
        };

        $movement = $this->stockService->ingredientEntry(
            $user,
            $ingredient,
            $quantity,
            $arguments['nota'] ?? null,
            null,
            $unitCost,
        );

        return [
            'movimiento_id' => $movement->id,
            'ingrediente' => (string) $ingredient->name,
            // La unidad viaja de vuelta a proposito: el usuario dice "5 de
            // queso" sin decir de que, y ver "5 kg" en la respuesta es como
            // detecta que el insumo estaba configurado en gramos.
            'unidad' => $ingredient->unit,
            'cantidad_ingresada' => $quantity,
            'stock_resultante' => (float) $ingredient->fresh()->stock,
        ];
    }
}
