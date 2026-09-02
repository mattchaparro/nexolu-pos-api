<?php

namespace App\Capabilities\Purchases;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Models\Business;
use App\Models\Supplier;
use App\Models\User;

/**
 * Tool: proveedores. Los proveedores del negocio con su historial de compras.
 *
 * De las 33 herramientas del chat del legacy, esta es la unica que quedo
 * registrada en ai_unanswered_questions como un hueco real de cobertura: un
 * dueño pregunto por sus proveedores y el asistente no supo responder.
 */
class SuppliersCapability implements Capability
{
    use CapsRows;

    public function requiredPermission(): ?string
    {
        return 'purchases.manage';
    }

    public function requiredFeature(): ?string
    {
        return 'inventory';
    }

    public function rules(): array
    {
        return [
            'nombre_proveedor' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $query = Supplier::query()
            ->withCount('purchases')
            ->withSum('purchaseLines as total_purchased', 'line_total_cop')
            ->withMax('purchases as last_purchase_at', 'purchased_at');

        if (! empty($arguments['nombre_proveedor'])) {
            // Escapa los comodines: el nombre viene del texto del usuario y un
            // % suelto convertiria el filtro en "todos".
            $query->where('name', 'like', '%'.addcslashes((string) $arguments['nombre_proveedor'], '%_\\').'%');
        }

        $suppliers = $query->orderByDesc('total_purchased')->limit(self::MAX_ROWS)->get();

        return [
            'total_proveedores' => $suppliers->count(),
            'proveedores' => $suppliers->map(fn (Supplier $supplier) => [
                'proveedor' => (string) $supplier->name,
                'telefono' => $supplier->phone,
                'nit' => $supplier->tax_id,
                'numero_compras' => (int) $supplier->purchases_count,
                'total_comprado' => round((float) $supplier->total_purchased, 2),
                'ultima_compra' => $supplier->last_purchase_at
                    ? substr((string) $supplier->last_purchase_at, 0, 10)
                    : null,
                'dias_sin_comprarle' => $supplier->last_purchase_at
                    ? (int) now()->diffInDays($supplier->last_purchase_at)
                    : null,
            ])->values()->all(),
            'nota' => 'ultima_compra en null significa que el proveedor esta registrado pero nunca '
                .'se le ha registrado una compra.',
        ];
    }
}
