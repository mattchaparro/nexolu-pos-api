<?php

namespace App\Capabilities\Sales;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Models\Business;
use App\Models\Sale;
use App\Models\User;

/** Tool: cuentas_abiertas. Mesas o cuentas sin cerrar en este momento. */
class OpenTabsCapability implements Capability
{
    use CapsRows;

    public function requiredPermission(): ?string
    {
        return null;
    }

    public function requiredFeature(): ?string
    {
        return 'open_tabs';
    }

    public function rules(): array
    {
        return [];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $sales = Sale::query()
            ->where('status', 'open')
            ->with(['user:id,name', 'table:id,name'])
            ->orderBy('created_at')
            ->limit(self::MAX_ROWS)
            ->get(['id', 'total', 'created_at', 'user_id', 'table_id', 'customer_name']);

        $tabs = $sales->map(fn (Sale $sale) => [
            'venta_id' => $sale->id,
            'mesa' => $sale->table?->name,
            'cliente' => $sale->customer_name,
            'abierta_desde' => $sale->created_at->format('Y-m-d H:i'),
            'minutos_abierta' => (int) $sale->created_at->diffInMinutes(now()),
            'consumo_actual' => round((float) $sale->total, 2),
            'atendida_por' => $sale->user?->name ?? 'Sin asignar',
        ])->values()->all();

        return [
            'total_cuentas_abiertas' => count($tabs),
            'consumo_total' => round(array_sum(array_column($tabs, 'consumo_actual')), 2),
            'cuentas' => $tabs,
        ];
    }
}
