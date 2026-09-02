<?php

namespace App\Capabilities\Purchases;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Capabilities\Support\ResolvesDateRange;
use App\Models\Business;
use App\Models\Purchase;
use App\Models\User;

/** Tool: compras_resumen. Cuanto se le compro a cada proveedor en un periodo. */
class PurchasesSummaryCapability implements Capability
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
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        [$start, $end] = $this->resolveDateRange($arguments['desde'] ?? null, $arguments['hasta'] ?? null);

        $purchases = Purchase::query()
            ->whereBetween('purchased_at', [$start->toDateString(), $end->toDateString()])
            ->with(['supplier:id,name', 'lines:id,purchase_id,line_total_cop'])
            ->get(['id', 'supplier_id', 'purchased_at']);

        $bySupplier = [];
        foreach ($purchases as $purchase) {
            $name = $purchase->supplier?->name ?? 'Sin proveedor';
            $bySupplier[$name]['numero_compras'] = ($bySupplier[$name]['numero_compras'] ?? 0) + 1;
            $bySupplier[$name]['total_comprado'] = ($bySupplier[$name]['total_comprado'] ?? 0.0)
                + (float) $purchase->lines->sum('line_total_cop');
        }

        $suppliers = [];
        foreach ($bySupplier as $name => $data) {
            $suppliers[] = [
                'proveedor' => $name,
                'numero_compras' => $data['numero_compras'],
                'total_comprado' => round($data['total_comprado'], 2),
            ];
        }

        usort($suppliers, fn ($a, $b) => $b['total_comprado'] <=> $a['total_comprado']);

        return [
            'desde' => $start->toDateString(),
            'hasta' => $end->toDateString(),
            'total_comprado' => round(array_sum(array_column($suppliers, 'total_comprado')), 2),
            'numero_compras' => $purchases->count(),
            'por_proveedor' => $this->capRows($suppliers),
        ];
    }
}
