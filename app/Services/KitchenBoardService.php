<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Comandera de cocina: seguimiento de preparacion por item.
 *
 * Entran dos cosas: las cuentas abiertas (mesas/comandas, salen al cobrarse)
 * y los pedidos online confirmados. Un pedido online NO es una cuenta
 * abierta: su venta ya esta cerrada (lo pago la pasarela o el negocio
 * verifico el pago), y sale de la comandera cuando se despacha o se entrega.
 */
class KitchenBoardService
{
    /** Estados del pedido online en los que la cocina todavia tiene algo que hacer. */
    const ORDER_IN_KITCHEN = [Order::STATUS_CONFIRMED, Order::STATUS_PREPARING];

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
            ->where(fn (Builder $query) => $query
                ->where('status', 'open')
                ->orWhere(fn (Builder $online) => $online
                    ->whereNotNull('kitchen_status')
                    ->whereHas('onlineOrder', fn (Builder $order) => $order->whereIn('status', self::ORDER_IN_KITCHEN))))
            ->whereHas('items')
            ->with(['items.product:id,name', 'items.options', 'user:id,name', 'table:id,name', 'onlineOrder'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Manda a cocina la venta de un pedido online: todos sus items quedan
     * pendientes. Una cuenta abierta no pasa por aca (nace pendiente).
     */
    public function sendToKitchen(Sale $sale): void
    {
        $sale->items()->update(['kitchen_status' => self::STATUS_PENDING, 'kitchen_updated_at' => now()]);
        $sale->update(['kitchen_status' => self::STATUS_PENDING, 'kitchen_updated_at' => now()]);
    }

    /**
     * Pedido online listo que sale del local: a domicilio queda "enviado" (y
     * el comprador recibe el aviso), si lo recogen queda "entregado". Con eso
     * deja la comandera.
     */
    public function dispatch(User $actor, Sale $sale): Order
    {
        $order = $sale->onlineOrder;
        if ($order === null || ! in_array($order->status, self::ORDER_IN_KITCHEN, true)) {
            throw ValidationException::withMessages([
                'sale' => 'Esta comanda no es un pedido online pendiente de salir.',
            ]);
        }

        $status = $order->is_pickup ? Order::STATUS_DELIVERED : Order::STATUS_SHIPPED;
        $orders = app(OrderService::class);
        $order = $orders->transition($actor, $order, $status, 'Despachado desde la comandera');
        $orders->notifyBuyer($order, $status);

        return $order;
    }

    /**
     * Actualiza el kitchen_status de los items indicados (o todos los de la
     * venta si $saleItemIds viene vacio) y recalcula el rollup de la venta.
     *
     * Si la comanda es un pedido online y la cocina ya lo empezo, el pedido
     * pasa a "en preparacion": es lo que ve el comprador al seguirlo.
     *
     * @param  list<int>  $saleItemIds
     */
    public function updateStatus(Sale $sale, string $status, array $saleItemIds = [], ?User $actor = null): void
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

        $order = $sale->onlineOrder;
        if ($actor !== null && $order?->status === Order::STATUS_CONFIRMED && $status !== self::STATUS_PENDING) {
            app(OrderService::class)->transition($actor, $order, Order::STATUS_PREPARING, 'En preparación en la comandera');
        }
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
