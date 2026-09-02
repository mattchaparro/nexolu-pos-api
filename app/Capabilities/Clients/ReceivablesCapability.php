<?php

namespace App\Capabilities\Clients;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Models\Business;
use App\Models\Receivable;
use App\Models\User;

/** Tool: fiados_pendientes. Quien debe, cuanto y desde cuando. */
class ReceivablesCapability implements Capability
{
    use CapsRows;

    public function requiredPermission(): ?string
    {
        return 'receivables.manage';
    }

    public function requiredFeature(): ?string
    {
        return 'receivables';
    }

    public function rules(): array
    {
        return [
            'nombre_cliente' => ['sometimes', 'nullable', 'string', 'max:150'],
            'incluir_pagados' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $query = Receivable::query();

        if (empty($arguments['incluir_pagados'])) {
            $query->where('status', '!=', 'paid')->where('balance', '>', 0);
        }

        if (! empty($arguments['nombre_cliente'])) {
            $like = '%'.addcslashes((string) $arguments['nombre_cliente'], '%_\\').'%';
            $query->where('customer_name', 'like', $like);
        }

        // Se agrupa por customer_key (la identidad que ya usa el POS para los
        // fiados) y no por nombre: dos "Juan" distintos no deben sumarse en
        // una sola deuda.
        $rows = $query->groupBy('customer_key')
            ->selectRaw('MAX(customer_name) as cliente')
            ->selectRaw('MAX(customer_phone) as telefono')
            ->selectRaw('COUNT(*) as numero_fiados')
            ->selectRaw('SUM(balance) as saldo')
            ->selectRaw('MIN(created_at) as mas_antiguo')
            ->orderByDesc('saldo')
            ->limit(self::MAX_ROWS)
            ->get();

        $clients = $rows->map(fn ($row) => [
            'cliente' => $row->cliente ?: 'Sin nombre',
            'telefono' => $row->telefono,
            'numero_fiados' => (int) $row->numero_fiados,
            'saldo_pendiente' => round((float) $row->saldo, 2),
            'fiado_mas_antiguo' => substr((string) $row->mas_antiguo, 0, 10),
            'dias_del_mas_antiguo' => (int) now()->diffInDays($row->mas_antiguo),
        ])->values()->all();

        return [
            'total_clientes_con_deuda' => count($clients),
            'saldo_total_pendiente' => round(array_sum(array_column($clients, 'saldo_pendiente')), 2),
            'clientes' => $this->capRows($clients),
        ];
    }
}
