<?php

namespace App\Capabilities\Purchases;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Models\Business;
use App\Models\Purchase;
use App\Models\User;

/** Tool: cuentas_por_pagar. Compras pendientes de pago, por proveedor. */
class PayablesCapability implements Capability
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
        $query = Purchase::query()
            ->where('payment_status', 'pending')
            ->with(['supplier:id,name', 'lines:id,purchase_id,line_total_cop', 'payments:id,purchase_id,amount']);

        if (! empty($arguments['nombre_proveedor'])) {
            $like = '%'.addcslashes((string) $arguments['nombre_proveedor'], '%_\\').'%';
            $query->whereHas('supplier', fn ($sub) => $sub->where('name', 'like', $like));
        }

        $bySupplier = [];

        foreach ($query->get() as $purchase) {
            // El saldo sale del accessor del modelo (total - abonos), que es la
            // misma cuenta que muestra la pantalla de compras. El legacy la
            // rehacia con dos subconsultas porque unir lineas Y abonos en el
            // mismo join multiplica el total por cada combinacion.
            $balance = round((float) $purchase->balance, 2);

            if ($balance <= 0.009) {
                continue;
            }

            $name = $purchase->supplier?->name ?? 'Sin proveedor';
            $bySupplier[$name]['numero_compras'] = ($bySupplier[$name]['numero_compras'] ?? 0) + 1;
            $bySupplier[$name]['saldo_pendiente'] = ($bySupplier[$name]['saldo_pendiente'] ?? 0.0) + $balance;

            $oldest = $bySupplier[$name]['mas_antigua'] ?? null;
            $purchasedAt = substr((string) $purchase->purchased_at, 0, 10);
            if ($oldest === null || $purchasedAt < $oldest) {
                $bySupplier[$name]['mas_antigua'] = $purchasedAt;
            }
        }

        $suppliers = [];
        foreach ($bySupplier as $name => $data) {
            $suppliers[] = [
                'proveedor' => $name,
                'numero_compras' => $data['numero_compras'],
                'saldo_pendiente' => round($data['saldo_pendiente'], 2),
                'compra_mas_antigua' => $data['mas_antigua'],
                'dias_desde_la_mas_antigua' => (int) now()->diffInDays($data['mas_antigua']),
            ];
        }

        usort($suppliers, fn ($a, $b) => $b['saldo_pendiente'] <=> $a['saldo_pendiente']);

        return [
            'total_proveedores_con_deuda' => count($suppliers),
            'saldo_total_pendiente' => round(array_sum(array_column($suppliers, 'saldo_pendiente')), 2),
            'proveedores' => $this->capRows($suppliers),
        ];
    }
}
