<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Storefront\StoreStorefrontOrderRequest;
use App\Http\Resources\Api\V1\Storefront\StorefrontOrderResource;
use App\Models\Order;
use App\Services\OnlineOrderNotifier;
use App\Services\OnlineOrderPaymentService;
use App\Services\OrderService;
use App\Services\PaymentsCoreService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Checkout y seguimiento de pedidos, del lado del COMPRADOR ANONIMO.
 *
 * El negocio lo resuelve ResolveStorefrontTenant desde el slug, igual que en
 * el catalogo. El seguimiento va por `public_token` y no por id: un
 * comprador sin cuenta necesita una llave para volver a su pedido, y una
 * secuencia de ids seria una invitacion a leer los pedidos de los demas.
 */
class StorefrontOrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function store(StoreStorefrontOrderRequest $request): StorefrontOrderResource
    {
        $business = TenantContext::current();
        abort_unless($business !== null, 404);

        $order = $this->orders->createFromStorefront($business, $request->validated());
        // El link de pago se pide despues de crear el pedido, no antes: si
        // la pasarela esta caida el pedido igual queda registrado (ver
        // OrderService::attachPaymentLink).
        $order = $this->orders->attachPaymentLink($order);
        $this->orders->notifyMerchant($order);
        // Y por WhatsApp, que es el que de verdad se ve: quien atiende el
        // mostrador no revisa el correo.
        $this->orders->notifyMerchantOnWhatsApp($order);
        // Y al comprador, que hasta ahora no recibia nada despues de comprar.
        app(OnlineOrderNotifier::class)->sendReceived($order);

        return new StorefrontOrderResource($order);
    }

    public function show(Request $request): StorefrontOrderResource
    {
        return new StorefrontOrderResource($this->findByToken($request));
    }

    /**
     * Confirmar el pago preguntandole a la pasarela, sin esperar el webhook.
     *
     * Lo llama la tienda cuando el comprador vuelve de pagar. El webhook
     * sigue siendo el camino normal, pero no alcanza solo: Bold no manda
     * webhooks en su ambiente de pruebas y en produccion se toma hasta 10
     * minutos. Durante todo ese rato el comprador que YA pago ve su pedido
     * como "esperando el pago", con un boton que lo invita a pagar otra vez.
     *
     * Es idempotente contra el webhook: los dos terminan en
     * `OnlineOrderPaymentService::approve`, que solo actua sobre pedidos
     * pendientes. Gane quien gane la carrera, la venta se crea una vez.
     *
     * Nunca falla hacia afuera: si la pasarela no responde, el comprador ve
     * su pedido tal como esta -- que es exactamente lo que veria sin esto.
     */
    public function syncPayment(Request $request): StorefrontOrderResource
    {
        $order = $this->findByToken($request);

        if ($order->status !== Order::STATUS_PENDING || $order->payment_reference === null) {
            return new StorefrontOrderResource($order);
        }

        $gateway = $order->business?->activePaymentGateway();
        if ($gateway === null) {
            return new StorefrontOrderResource($order);
        }

        try {
            $estado = app(PaymentsCoreService::class)
                ->usingGateway($gateway)
                ->refreshTransaction($order->payment_reference);
        } catch (\Throwable $e) {
            Log::warning('online_order.sync_payment_failed', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return new StorefrontOrderResource($order);
        }

        if (($estado['status'] ?? null) === 'approved') {
            app(OnlineOrderPaymentService::class)->approve($order);
        }

        return new StorefrontOrderResource($order->refresh()->load('items'));
    }

    private function findByToken(Request $request): Order
    {
        $business = TenantContext::current();
        abort_unless($business !== null, 404);

        $order = Order::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('public_token', (string) $request->route('token'))
            ->with('items')
            ->first();

        abort_if($order === null, 404);

        return $order;
    }
}
