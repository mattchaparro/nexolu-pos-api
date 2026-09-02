<?php

namespace App\Capabilities\Inventory;

use App\Capabilities\Capability;
use App\Capabilities\Support\ResolvesProductByName;
use App\Models\Business;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Validation\ValidationException;

/**
 * Tool: crear_entrada_inventario (escritura). Surtir un producto.
 *
 * SUMA al stock, no lo reemplaza. La distincion importa: "surti 15 empanadas"
 * y "el stock de empanadas quedo en 15" son operaciones distintas, y
 * confundirlas deja el inventario mal. El ajuste a un numero exacto no se
 * expone por chat a proposito - se hace en la ficha del producto, donde el
 * usuario ve el stock actual antes de cambiarlo.
 */
class CreateStockEntryCapability implements Capability
{
    use ResolvesProductByName;

    public function __construct(private StockService $stockService) {}

    public function requiredPermission(): ?string
    {
        return 'inventory.add';
    }

    public function requiredFeature(): ?string
    {
        return 'inventory';
    }

    public function rules(): array
    {
        return [
            'producto' => ['required', 'string', 'max:200'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'nota' => ['sometimes', 'nullable', 'string', 'max:500'],
            'valor_total' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'valor_unitario' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $product = $this->resolveProductByName((string) $arguments['producto']);
        $quantity = (float) $arguments['cantidad'];

        $movement = $this->stockService->entry(
            $user,
            $product,
            $quantity,
            $arguments['nota'] ?? null,
            null,
            $this->unitCost($arguments, $quantity),
        );

        return [
            'movimiento_id' => $movement->id,
            'producto' => (string) $product->name,
            'cantidad_ingresada' => $quantity,
            'stock_resultante' => (float) $product->fresh()->stock,
        ];
    }

    /**
     * El usuario dice lo que pago de una de dos formas ("me costaron 50.000"
     * o "cada una a 5.000"); el POS solo entiende costo unitario.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function unitCost(array $arguments, float $quantity): ?float
    {
        $hasTotal = isset($arguments['valor_total']);
        $hasUnit = isset($arguments['valor_unitario']);

        if ($hasTotal && $hasUnit) {
            throw ValidationException::withMessages([
                'valor_total' => 'Da el valor total o el valor por unidad, no los dos.',
            ]);
        }

        if ($hasUnit) {
            return (float) $arguments['valor_unitario'];
        }

        // El costo es opcional: sin el, StockService deja el costo promedio
        // del producto como estaba, que es lo correcto para una reposicion de
        // la que no se sabe el precio.
        return $hasTotal && $quantity > 0 ? (float) $arguments['valor_total'] / $quantity : null;
    }
}
