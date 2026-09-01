<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Que pasa con un pedido de la tienda cuando la pasarela resuelve el cobro.
 *
 * Hay DOS formas de enterarse del mismo hecho y por eso esto es un servicio
 * y no un metodo del controlador de webhooks:
 *
 * 1. El webhook del Payments Core. Es el camino normal: empujado y gratis.
 * 2. La consulta activa, cuando el comprador vuelve de pagar. Hace falta
 *    porque el webhook no alcanza -- Bold no lo manda en su ambiente de
 *    pruebas y en produccion se toma hasta 10 minutos, y el comprador
 *    vuelve a la tienda mucho antes que eso.
 *
 * Las dos terminan aca, y aca la guarda de idempotencia es el estado del
 * pedido: gane quien gane la carrera, la venta se crea una sola vez.
 */
class OnlineOrderPaymentService
{
    public function __construct(private OrderService $orders) {}

    /**
     * El pago entro: aca nace la venta.
     *
     * Es la regla del proyecto -- la `Sale` se crea cuando la plata entro,
     * no cuando el comprador apreto "hacer pedido". Se delega en
     * `OrderService::transition` para que pase por `SaleService` y
     * `StockService` exactamente igual que una venta de mostrador, con sus
     * movimientos de stock auditados.
     *
     * Devuelve si este llamado fue el que lo confirmo (false si ya estaba
     * resuelto o si no se pudo facturar).
     */
    public function approve(Order $order): bool
    {
        if ($order->status !== Order::STATUS_PENDING) {
            return false;
        }

        $business = Business::withoutGlobalScopes()->find($order->business_id);
        // `sales.user_id` es NOT NULL y en una venta online no hay cajero:
        // el vendedor es el dueño del negocio.
        $owner = $business?->users()->where('is_business_owner', true)->first();
        if ($business === null || $owner === null) {
            Log::error('online_order.pago: sin dueño para facturar', ['order_id' => $order->id]);

            return false;
        }

        try {
            $this->orders->transition(
                $owner,
                $order,
                Order::STATUS_CONFIRMED,
                'Pago aprobado por '.($order->payment_provider ?? 'la pasarela'),
                $this->paymentMethodFor($business),
            );
            $order->forceFill(['paid_at' => now()])->save();
            $this->orders->notifyBuyer($order->fresh(['items']), Order::STATUS_CONFIRMED);

            return true;
        } catch (Throwable $e) {
            // Nunca se rechaza un pago ya cobrado: el pedido queda pendiente
            // y el comerciante lo resuelve a mano desde la bandeja.
            Log::error('online_order.pago: no se pudo facturar', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Con que medio se registra una venta cobrada por la pasarela.
     *
     * El catalogo de medios lo define cada negocio, asi que no se puede
     * fijar uno por codigo (asi se rompio el confirmar manual: 'transfer'
     * cableado contra un negocio que no lo tenia). Se busca el mas parecido
     * a "pago electronico" entre los habilitados y, si no hay ninguno, se
     * cae al primero que no sea fiado -- el proveedor real igual queda
     * registrado en `orders.payment_provider`.
     */
    public function paymentMethodFor(Business $business): ?string
    {
        $allowed = $business->allowedPaymentMethodIds();
        foreach (['card', 'tarjeta', 'transfer', 'transferencia', 'nequi', 'daviplata'] as $preferred) {
            if (in_array($preferred, $allowed, true)) {
                return $preferred;
            }
        }

        foreach ($allowed as $method) {
            if (! $business->isCreditPaymentMethod($method)) {
                return $method;
            }
        }

        return null;
    }
}
