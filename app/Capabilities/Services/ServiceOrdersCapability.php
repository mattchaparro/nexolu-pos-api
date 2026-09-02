<?php

namespace App\Capabilities\Services;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Capabilities\Support\ResolvesDateRange;
use App\Models\Business;
use App\Models\ServiceOrder;
use App\Models\User;

/** Tool: servicios_estado. Ordenes de servicio, lo abonado y lo que falta. */
class ServiceOrdersCapability implements Capability
{
    use CapsRows, ResolvesDateRange;

    public function requiredPermission(): ?string
    {
        return 'appointments.manage';
    }

    public function requiredFeature(): ?string
    {
        return 'services';
    }

    public function rules(): array
    {
        return [
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'nombre_cliente' => ['sometimes', 'nullable', 'string', 'max:150'],
            'solo_pendientes' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        [$start, $end] = $this->resolveDateRange($arguments['desde'] ?? null, $arguments['hasta'] ?? null);

        $query = ServiceOrder::query()
            ->whereBetween('created_at', [$start, $end])
            ->with('client:id,name');

        if (! empty($arguments['nombre_cliente'])) {
            $like = '%'.addcslashes((string) $arguments['nombre_cliente'], '%_\\').'%';
            $query->whereHas('client', fn ($sub) => $sub->where('name', 'like', $like));
        }

        $orders = $query->orderByDesc('created_at')->limit(self::MAX_ROWS)->get();

        $rows = $orders->map(function (ServiceOrder $order) {
            $total = (float) $order->total;
            $paid = (float) $order->amount_paid;

            return [
                'orden_id' => $order->id,
                'servicio' => (string) $order->service_name,
                'cliente' => $order->client?->name ?? 'Sin cliente asociado',
                'estado' => $order->status,
                'fecha' => $order->created_at->toDateString(),
                'total' => round($total, 2),
                'abonado' => round($paid, 2),
                'saldo_pendiente' => round($total - $paid, 2),
            ];
        })->values()->all();

        if (! empty($arguments['solo_pendientes'])) {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['saldo_pendiente'] > 0));
        }

        return [
            'desde' => $start->toDateString(),
            'hasta' => $end->toDateString(),
            'total_ordenes' => count($rows),
            'saldo_pendiente_total' => round(array_sum(array_column($rows, 'saldo_pendiente')), 2),
            'cobrado_total' => round(array_sum(array_column($rows, 'abonado')), 2),
            'ordenes' => $this->capRows($rows),
        ];
    }
}
