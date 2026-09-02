<?php

namespace App\Capabilities\Purchases;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Capabilities\Support\ResolvesDateRange;
use App\Models\Business;
use App\Models\Purchase;
use App\Models\User;

/**
 * Tool: compras_detalle. Las compras una por una, no agregadas por proveedor.
 *
 * compras_resumen responde "a quien le compro mas"; esta responde "cual fue la
 * compra mas cara" y "que compre el mes pasado", que son preguntas sobre
 * facturas concretas y no sobre totales.
 */
class PurchasesDetailCapability implements Capability
{
    use CapsRows, ResolvesDateRange;

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
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'orden' => ['sometimes', 'nullable', 'in:mas_costosa,mas_reciente,menos_costosa'],
            'nombre_proveedor' => ['sometimes', 'nullable', 'string', 'max:150'],
            'limite' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        [$start, $end] = $this->resolveDateRange($arguments['desde'] ?? null, $arguments['hasta'] ?? null);

        $order = $arguments['orden'] ?? 'mas_costosa';
        $limit = min((int) ($arguments['limite'] ?? 10), self::MAX_ROWS);

        $query = Purchase::query()
            ->whereBetween('purchased_at', [$start->toDateString(), $end->toDateString()])
            ->with(['supplier:id,name', 'user:id,name', 'lines:id,purchase_id,line_total_cop']);

        if (! empty($arguments['nombre_proveedor'])) {
            $like = '%'.addcslashes((string) $arguments['nombre_proveedor'], '%_\\').'%';
            $query->whereHas('supplier', fn ($sub) => $sub->where('name', 'like', $like));
        }

        // El total de una compra es la suma de sus lineas, no una columna, asi
        // que ordenar por monto no se puede hacer en SQL sin un join que
        // multiplique filas. Se ordena en memoria; el rango de fechas y el
        // tope de filas ya acotan cuanto se trae.
        $purchases = $query->orderByDesc('purchased_at')->limit(self::MAX_ROWS)->get();

        $rows = $purchases->map(fn (Purchase $purchase) => [
            'compra_id' => $purchase->id,
            'fecha' => substr((string) $purchase->purchased_at, 0, 10),
            'proveedor' => $purchase->supplier?->name ?? 'Sin proveedor',
            'factura' => $purchase->invoice_number,
            'total' => round((float) $purchase->lines->sum('line_total_cop'), 2),
            'productos_distintos' => $purchase->lines->count(),
            'registrada_por' => $purchase->user?->name ?? 'Desconocido',
            'estado_pago' => $purchase->payment_status,
        ])->values()->all();

        usort($rows, fn ($a, $b) => match ($order) {
            'mas_reciente' => strcmp($b['fecha'], $a['fecha']),
            'menos_costosa' => $a['total'] <=> $b['total'],
            default => $b['total'] <=> $a['total'],
        });

        return [
            'desde' => $start->toDateString(),
            'hasta' => $end->toDateString(),
            'orden' => $order,
            'compras' => array_slice($rows, 0, $limit),
        ];
    }
}
