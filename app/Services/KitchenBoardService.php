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
 * Entran las cuentas abiertas (mesas/comandas, salen al cobrarse), los
 * pedidos online confirmados y los domicilios vendidos en el mostrador. Un pedido online NO es una cuenta
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
                // Ventas ya cobradas que la cocina todavia tiene que hacer: un
                // pedido online que no ha salido, o un domicilio del mostrador
                // que el domiciliario no ha tomado.
                ->orWhere(fn (Builder $paid) => $paid
                    ->whereNotNull('kitchen_status')
                    ->where(fn (Builder $pending) => $pending
                        ->whereHas('onlineOrder', fn (Builder $order) => $order->whereIn('status', self::ORDER_IN_KITCHEN))
                        ->orWhere('delivery_status', DeliveryService::STATUS_PENDING))))
            // Un domicilio que ya tomo el domiciliario salio de la cocina.
            ->where(fn (Builder $query) => $query
                ->whereNull('delivery_status')
                ->orWhere('delivery_status', DeliveryService::STATUS_PENDING))
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
     * Pedido online para recoger que el cliente ya se llevo: queda
     * "entregado" y deja la comandera. Los domicilios no salen de aca sino de
     * la pantalla de Domicilios, cuando un domiciliario los toma.
     */
    public function dispatch(User $actor, Sale $sale): Order
    {
        $order = $sale->onlineOrder;
        if ($order === null || ! in_array($order->status, self::ORDER_IN_KITCHEN, true)) {
            throw ValidationException::withMessages([
                'sale' => 'Esta comanda no es un pedido online pendiente de salir.',
            ]);
        }
        if (! $order->is_pickup) {
            throw ValidationException::withMessages([
                'sale' => 'Los domicilios salen desde Domicilios, cuando un domiciliario los toma.',
            ]);
        }

        $status = Order::STATUS_DELIVERED;
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
    public function syncSaleStatus(Sale $sale): void
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
