<?php

namespace App\Capabilities\Inventory;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Capabilities\Support\ResolvesProductByName;
use App\Models\Business;
use App\Models\ProductCostHistory;
use App\Models\PurchaseLine;
use App\Models\User;

/**
 * Tool: variacion_costo_producto. Como cambio lo que cuesta comprar algo.
 *
 * Responde dos preguntas que el dueño hace seguido y no tiene forma facil de
 * mirar: "me subieron el precio de esto?" y "a que proveedor me sale mas
 * barato?".
 */
class ProductCostVariationCapability implements Capability
{
    use CapsRows, ResolvesProductByName;

    public function requiredPermission(): ?string
    {
        return 'reports.inventory';
    }

    public function requiredFeature(): ?string
    {
        return 'inventory';
    }

    public function rules(): array
    {
        return [
            'nombre_producto' => ['required', 'string', 'max:200'],
            'limite_compras' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $product = $this->resolveProductByName((string) $arguments['nombre_producto']);

        $lines = PurchaseLine::query()
            ->where('product_id', $product->id)
            ->whereNotNull('unit_cost_cop')
            ->where('unit_cost_cop', '>', 0)
            ->whereHas('purchase', fn ($query) => $query->where('business_id', $business->id))
            ->with('purchase:id,purchased_at,supplier_id', 'purchase.supplier:id,name')
            ->limit(min((int) ($arguments['limite_compras'] ?? 20), self::MAX_ROWS))
            ->get();

        $history = $lines
            ->sortByDesc(fn (PurchaseLine $line) => $line->purchase?->purchased_at)
            ->map(fn (PurchaseLine $line) => [
                'fecha' => substr((string) $line->purchase?->purchased_at, 0, 10),
                'proveedor' => $line->purchase?->supplier?->name ?? 'Sin proveedor',
                'costo_unitario' => round((float) $line->unit_cost_cop, 2),
                'cantidad' => round((float) $line->quantity, 2),
            ])->values()->all();

        $costs = array_column($history, 'costo_unitario');

        // Promedio PONDERADO por unidades, no simple: comparar promedios
        // simples castigaria a quien vendio caro una sola vez frente a quien
        // vende siempre.
        $bySupplier = [];
        foreach ($history as $row) {
            $name = $row['proveedor'];
            $bySupplier[$name]['compras'] = ($bySupplier[$name]['compras'] ?? 0) + 1;
            $bySupplier[$name]['unidades'] = ($bySupplier[$name]['unidades'] ?? 0.0) + $row['cantidad'];
            $bySupplier[$name]['gasto'] = ($bySupplier[$name]['gasto'] ?? 0.0)
                + ($row['costo_unitario'] * $row['cantidad']);
        }

        $comparison = [];
        foreach ($bySupplier as $name => $data) {
            $comparison[] = [
                'proveedor' => $name,
                'compras' => $data['compras'],
                'costo_promedio' => $data['unidades'] > 0 ? round($data['gasto'] / $data['unidades'], 2) : 0.0,
            ];
        }
        usort($comparison, fn ($a, $b) => $a['costo_promedio'] <=> $b['costo_promedio']);

        // Cambios de costo que no vienen de una compra (edicion manual de la
        // ficha): sin esto un salto de costo parece inexplicable.
        $manualChanges = ProductCostHistory::query()
            ->where('product_id', $product->id)
            ->whereNull('purchase_id')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['created_at', 'cost_before', 'cost_after', 'source'])
            ->map(fn (ProductCostHistory $change) => [
                'fecha' => $change->created_at->toDateString(),
                'costo_anterior' => round((float) $change->cost_before, 2),
                'costo_nuevo' => round((float) $change->cost_after, 2),
                'origen' => $change->source,
            ])->values()->all();

        return [
            'producto' => (string) $product->name,
            'costo_actual' => round((float) $product->cost_price, 2),
            'precio_venta' => round((float) $product->price, 2),
            'compras_registradas' => count($history),
            'costo_minimo' => $costs === [] ? null : round(min($costs), 2),
            'costo_maximo' => $costs === [] ? null : round(max($costs), 2),
            'variacion_porcentaje' => ($costs !== [] && min($costs) > 0)
                ? round(((max($costs) - min($costs)) / min($costs)) * 100, 1)
                : null,
            'comparativa_proveedores' => $comparison,
            'historial_compras' => $history,
            'cambios_manuales_de_costo' => $manualChanges,
        ];
    }
}
