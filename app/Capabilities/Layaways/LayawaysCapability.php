<?php

namespace App\Capabilities\Layaways;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Models\Business;
use App\Models\Layaway;
use App\Models\User;

/** Tool: apartados. Que hay separado, cuanto abonaron y cuanto falta. */
class LayawaysCapability implements Capability
{
    use CapsRows;

    public function requiredPermission(): ?string
    {
        return 'layaways.manage';
    }

    public function requiredFeature(): ?string
    {
        return 'layaway';
    }

    public function rules(): array
    {
        return [
            'estado' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $query = Layaway::query()->with(['items:id,layaway_id,quantity,unit_price', 'payments:id,layaway_id,amount']);

        if (! empty($arguments['estado'])) {
            $query->where('status', (string) $arguments['estado']);
        }

        $layaways = $query->orderByDesc('created_at')->limit(self::MAX_ROWS)->get();

        if ($layaways->isEmpty()) {
            return ['apartados' => [], 'nota' => 'No hay apartados registrados con ese criterio.'];
        }

        $rows = $layaways->map(function (Layaway $layaway) {
            $total = (float) $layaway->items->sum(fn ($item) => (float) $item->quantity * (float) $item->unit_price);
            $paid = (float) $layaway->payments->sum('amount');

            return [
                'apartado_id' => $layaway->id,
                'cliente' => $layaway->customer_name ?: 'Sin nombre',
                'estado' => $layaway->status,
                'fecha' => $layaway->created_at->toDateString(),
                'unidades' => round((float) $layaway->items->sum('quantity'), 2),
                'total' => round($total, 2),
                'abonado' => round($paid, 2),
                'saldo_pendiente' => round($total - $paid, 2),
            ];
        })->values()->all();

        return [
            'total_apartados' => count($rows),
            'saldo_pendiente_total' => round(array_sum(array_column($rows, 'saldo_pendiente')), 2),
            'apartados' => $this->capRows($rows),
        ];
    }
}
