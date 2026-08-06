<?php

namespace App\Services;

use App\Models\Sale;
use Illuminate\Database\Eloquent\Collection;

/**
 * Comandera de cocina: seguimiento de preparacion por item de las cuentas
 * abiertas (mesas/comandas), no de las ventas directas ya cerradas.
 */
class KitchenBoardService
{
    const STATUS_PENDING = 'pending';

    const STATUS_PREPARING = 'preparing';

    const STATUS_READY = 'ready';

    const STATUSES = [self::STATUS_PENDING, self::STATUS_PREPARING, self::STATUS_READY];

    /**
     * @return Collection<int, Sale>
     */
    public function openTickets(int $businessId): Collection
    {
        return Sale::query()
            ->where('business_id', $businessId)
            ->where('status', 'open')
            ->whereHas('items')
            ->with(['items.product:id,name', 'user:id,name', 'table:id,name'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Actualiza el kitchen_status de los items indicados (o todos los de la
     * venta si $saleItemIds viene vacio) y recalcula el rollup de la venta.
     *
     * @param  list<int>  $saleItemIds
     */
    public function updateStatus(Sale $sale, string $status, array $saleItemIds = []): void
    {
        $itemsQuery = $sale->items();
        if ($saleItemIds !== []) {
            $itemsQuery->whereIn('id', $saleItemIds);
        }

        $itemsQuery->update([
            'kitchen_status' => $status,
            'kitchen_updated_at' => now(),
        ]);

        $this->syncSaleStatus($sale);
    }

    public static function normalizeStatus(?string $status): string
    {
        return in_array($status, [self::STATUS_PREPARING, self::STATUS_READY], true)
            ? $status
            : self::STATUS_PENDING;
    }

    /**
     * El estado de la venta es el "peor" (menos avanzado) entre sus items:
     * si algo sigue pendiente, la comanda completa se muestra pendiente,
     * aunque otro item ya este listo.
     */
    private function syncSaleStatus(Sale $sale): void
    {
        $statuses = $sale->items()
            ->pluck('kitchen_status')
            ->map(fn (?string $status) => self::normalizeStatus($status));

        $resolved = match (true) {
            $statuses->contains(self::STATUS_PENDING) => self::STATUS_PENDING,
            $statuses->contains(self::STATUS_PREPARING) => self::STATUS_PREPARING,
            default => self::STATUS_READY,
        };

        $sale->update([
            'kitchen_status' => $resolved,
            'kitchen_updated_at' => now(),
        ]);
    }
}
