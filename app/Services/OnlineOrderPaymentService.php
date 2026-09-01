<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
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
        // La guarda se toma sobre la fila BLOQUEADA y el bloqueo se sostiene
        // hasta que la venta esta hecha.
        //
        // Sin esto se facturo dos veces en produccion (pedido #3): la
        // consulta activa hace que el Core resuelva la transaccion, y al
        // resolverla el Core dispara su webhook. Los dos caminos llegan aca
        // con un segundo de diferencia -- pero el `$order` que traia la
        // consulta se cargo ANTES, con `status = pending`, asi que su guarda
        // seguia dando verde despues de que el webhook ya habia facturado.
        // Resultado: dos ventas por el mismo pedido y el stock descontado
        // doble.
        //
        // Chequear contra la fila fresca no basta por si solo: si el bloqueo
        // se suelta antes de facturar, los dos vuelven a pasar la guarda.
        // Por eso la venta ocurre DENTRO de la misma transaccion.
        try {
            $facturado = DB::transaction(function () use ($order) {
                $fresco = Order::withoutGlobalScopes()
                    ->where('id', $order->id)
                    ->lockForUpdate()
                    ->first();

                if ($fresco === null || $fresco->status !== Order::STATUS_PENDING) {
                    return null;
                }

                $business = Business::withoutGlobalScopes()->find($fresco->business_id);
                // `sales.user_id` es NOT NULL y en una venta online no hay
                // cajero: el vendedor es el dueño del negocio.
                $owner = $business?->users()->where('is_business_owner', true)->first();
                if ($business === null || $owner === null) {
                    Log::error('online_order.pago: sin dueño para facturar', ['order_id' => $fresco->id]);

                    return null;
                }

                $this->orders->transition(
                    $owner,
                    $fresco,
                    Order::STATUS_CONFIRMED,
                    'Pago aprobado por '.($fresco->payment_provider ?? 'la pasarela'),
                    $this->paymentMethodFor($business),
                );
                $fresco->forceFill(['paid_at' => now()])->save();

                return $fresco;
            });
        } catch (Throwable $e) {
            // Nunca se rechaza un pago ya cobrado: la transaccion revierte,
            // el pedido queda pendiente y el comerciante lo resuelve a mano
            // desde la bandeja. Propagarlo solo lograria que la pasarela
            // reintentara el webhook contra el mismo error.
            Log::error('online_order.pago: no se pudo facturar', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        if ($facturado === null) {
            return false;
        }

        // Avisarle al comprador queda FUERA de la transaccion: no hay razon
        // para sostener el bloqueo de la fila mientras se encola un correo.
        $this->orders->notifyBuyer($facturado->fresh(['items']), Order::STATUS_CONFIRMED);

        return true;
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
