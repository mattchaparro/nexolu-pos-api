<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Client;
use App\Models\Order;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Domicilios: lo que sale del local a la casa del cliente, venga de un pedido
 * online, de Vender o de una cuenta abierta. Vive en la venta (ver la
 * migracion add_delivery_tracking_to_sales).
 *
 * pending (por llevar) -> on_the_way (un domiciliario lo tomo) -> delivered.
 * Un pedido online sigue el mismo camino: "enviado" al tomarlo (el comprador
 * ve "va en camino") y "entregado" al entregarlo.
 */
class DeliveryService
{
    const STATUS_PENDING = 'pending';

    const STATUS_ON_THE_WAY = 'on_the_way';

    const STATUS_DELIVERED = 'delivered';

    /**
     * Lo que ve la pantalla de Domicilios: los que faltan por llevar, los que
     * van en camino y los entregados hoy (para cuadrar con el domiciliario).
     * Un "por llevar" de mas de un dia no se muestra: en un negocio que no
     * usa esta pantalla se acumularian para siempre.
     *
     * @return Collection<int, Sale>
     */
    public function board(int $businessId): Collection
    {
        return Sale::query()
            ->where('business_id', $businessId)
            ->where('is_delivery', true)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $pending) => $pending
                    ->where('delivery_status', self::STATUS_PENDING)
                    ->where('created_at', '>=', now()->subDay()))
                ->orWhere('delivery_status', self::STATUS_ON_THE_WAY)
                ->orWhere(fn (Builder $done) => $done
                    ->where('delivery_status', self::STATUS_DELIVERED)
                    ->where('delivered_at', '>=', today())))
            ->with(['items.product:id,name', 'courier:id,name', 'onlineOrder'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Listo para salir: la cocina lo termino, o no pasa por cocina (venta de
     * mostrador, o negocio sin comandera).
     */
    public function isReady(Sale $sale, Business $business): bool
    {
        return ! $business->hasFeature('kitchen_board')
            || $sale->kitchen_status === null
            || $sale->kitchen_status === KitchenBoardService::STATUS_READY;
    }

    /** El domiciliario lo toma: queda a su nombre y "en camino". */
    public function take(User $courier, Sale $sale): Sale
    {
        $sale = DB::transaction(function () use ($courier, $sale) {
            // Bloqueo: dos domiciliarios tocando "Lo llevo" a la vez.
            $fresh = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($fresh->delivery_status !== self::STATUS_PENDING) {
                $who = $fresh->courier?->name;
                throw ValidationException::withMessages([
                    'delivery' => $who ? "Este domicilio ya lo lleva {$who}." : 'Este domicilio ya no está por llevar.',
                ]);
            }
            if (! $this->isReady($fresh, $courier->business)) {
                throw ValidationException::withMessages([
                    'delivery' => 'Todavía está en cocina.',
                ]);
            }

            $fresh->update([
                'courier_id' => $courier->id,
                'delivery_status' => self::STATUS_ON_THE_WAY,
                'delivery_taken_at' => now(),
            ]);

            $this->moveOrder($courier, $fresh, Order::STATUS_SHIPPED);

            return $fresh;
        });

        $this->notifyOrder($sale, Order::STATUS_SHIPPED);

        return $sale;
    }

    /** Entregado. Lo marca quien lo lleva, o un administrador. */
    public function deliver(User $actor, Sale $sale): Sale
    {
        if ($sale->delivery_status !== self::STATUS_ON_THE_WAY) {
            throw ValidationException::withMessages([
                'delivery' => 'Este domicilio no va en camino.',
            ]);
        }
        if ($sale->courier_id !== $actor->id && ! $actor->hasRole('admin')) {
            throw ValidationException::withMessages([
                'delivery' => 'Solo quien lo lleva puede marcarlo como entregado.',
            ]);
        }

        DB::transaction(function () use ($actor, $sale) {
            $sale->update([
                'delivery_status' => self::STATUS_DELIVERED,
                'delivered_at' => now(),
            ]);
            $this->moveOrder($actor, $sale, Order::STATUS_DELIVERED);
        });

        return $sale;
    }

    /**
     * La direccion de entrega es de la venta. La ficha del cliente guarda la
     * habitual: se llena sola si no tenia, y se cambia solo si quien cobra
     * lo pide (el cliente pudo pedir a otra parte esta vez).
     */
    public function rememberClientAddress(?int $clientId, ?string $address, bool $replace): void
    {
        $address = trim((string) $address);
        if ($clientId === null || $address === '') {
            return;
        }

        $client = Client::query()->find($clientId);
        if ($client !== null && ($replace || blank($client->address))) {
            $client->update(['address' => $address]);
        }
    }

    private function moveOrder(User $actor, Sale $sale, string $status): void
    {
        $order = $sale->onlineOrder;
        if ($order !== null && $order->canTransitionTo($status)) {
            app(OrderService::class)->transition($actor, $order, $status, $status === Order::STATUS_SHIPPED
                ? 'Lo lleva '.$actor->name
                : 'Entregado por '.$actor->name);
        }
    }

    private function notifyOrder(Sale $sale, string $status): void
    {
        $order = $sale->onlineOrder()->first();
        if ($order !== null && $order->status === $status) {
            app(OrderService::class)->notifyBuyer($order, $status);
        }
    }
}
