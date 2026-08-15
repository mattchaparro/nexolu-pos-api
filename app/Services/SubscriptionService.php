<?php

namespace App\Services;

use App\Models\Business;
use App\Models\SubscriptionCheckoutOrder;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Cobro de la suscripcion del negocio a Nexolu, orquestado via Nexolu
 * Payments Core (ver PaymentsCoreService) en vez de hablarle a Wompi
 * directo. La confirmacion real del pago (aprobado/rechazado) llega
 * despues por webhook firmado - ver PaymentsCoreWebhookController.
 */
class SubscriptionService
{
    /** Dias que otorga un ciclo de pago. Igual al default de Business::activate(). */
    private const SUBSCRIPTION_DAYS = 30;

    public function __construct(
        private PaymentsCoreService $paymentsCore,
        private SubscriptionPricingService $pricing,
    ) {}

    /**
     * Crea una orden pendiente y, con esa referencia, un intent de cobro en
     * el Payments Core. La orden se crea ANTES de llamar al Core (igual que
     * el legacy con Wompi): es lo que permite correlacionar el webhook de
     * confirmacion despues, y no depende de que la llamada al Core tenga
     * exito para existir.
     *
     * @return array{order_key: string, amount_cop: int, checkout: array<string, mixed>, payment_init: array<string, mixed>|null}
     */
    public function initiateCheckout(Business $business, User $user, string $redirectUrl, string $flow = 'widget'): array
    {
        $amount = $this->pricing->totalCop($business);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount_cop' => 'Monto no configurado.',
            ]);
        }

        $reference = 'NEX-'.$business->id.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));

        $order = SubscriptionCheckoutOrder::create([
            'business_id' => $business->id,
            'order_key' => $reference,
            'amount_cop' => $amount,
            'subscription_days' => self::SUBSCRIPTION_DAYS,
            'status' => 'pending',
            'provider' => 'wompi',
        ]);

        try {
            $intent = $this->paymentsCore->createIntent(
                reference: $reference,
                amountCop: $amount,
                customer: ['email' => $user->email, 'full_name' => $user->name],
                redirectUrl: $redirectUrl,
                metadata: ['business_id' => $business->id, 'subscription_days' => self::SUBSCRIPTION_DAYS],
                flow: $flow,
            );
        } catch (\Throwable $e) {
            // El Core nunca llego a registrar este intent: no queda nada que
            // un webhook pueda confirmar despues, no tiene sentido dejar la
            // orden huerfana en pending.
            $order->delete();

            throw $e;
        }

        if (! empty($intent['provider']) && $intent['provider'] !== $order->provider) {
            $order->update(['provider' => $intent['provider']]);
        }

        return [
            'order_key' => $reference,
            'amount_cop' => $amount,
            'checkout' => $intent['checkout'] ?? [],
            // Solo viene poblado si se pidio flow="api" - lo necesita el
            // frontend para tokenizar tarjeta directo con Wompi (nunca con
            // este backend) antes de llamar chargeCheckout().
            'payment_init' => $intent['payment_init'] ?? null,
        ];
    }

    /**
     * API directa: reenvia al Core el cobro de una orden ya creada con
     * flow="api" (tarjeta ya tokenizada por el frontend, o Nequi/PSE/Boton
     * Bancolombia). Verifica que la orden le pertenezca a este negocio y
     * siga pendiente ANTES de reenviar - mismo criterio de scoping que
     * checkoutStatus(). El resultado del Core es solo el ACK inmediato del
     * proveedor; la confirmacion real sigue llegando por
     * PaymentsCoreWebhookController.
     *
     * @param  array<string, mixed>  $paymentMethod
     * @return array<string, mixed>
     */
    public function chargeCheckout(Business $business, string $reference, array $paymentMethod): array
    {
        SubscriptionCheckoutOrder::where('business_id', $business->id)
            ->where('order_key', $reference)
            ->where('status', 'pending')
            ->firstOrFail();

        return $this->paymentsCore->charge($reference, $paymentMethod);
    }

    /**
     * @return array<string, mixed>
     */
    public function pricingBreakdown(Business $business): array
    {
        return $this->pricing->breakdown($business);
    }

    /**
     * Estado de una orden de checkout, para que el frontend haga polling
     * mientras espera el webhook de confirmacion (fallback de UX, igual
     * proposito que GET /v1/payments/transactions/{reference} del Core -
     * ver docs/APP_INTEGRATION.md seccion 2). Scoped al negocio del usuario
     * autenticado: una orden de otro negocio no debe ser consultable.
     *
     * @return array<string, mixed>
     */
    public function checkoutStatus(Business $business, string $reference): array
    {
        $order = SubscriptionCheckoutOrder::where('business_id', $business->id)
            ->where('order_key', $reference)
            ->firstOrFail();

        $result = [
            'order_key' => $order->order_key,
            'status' => $order->status,
            'amount_cop' => $order->amount_cop,
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
        ];

        if ($order->status === 'pending') {
            // El Core es la fuente de verdad, pero es solo un fallback de UX:
            // si no responde, el frontend se queda con lo que ya sabemos
            // localmente y sigue esperando el webhook.
            try {
                $transaction = $this->paymentsCore->getTransaction($reference);
                $result['provider_status'] = $transaction['status'] ?? null;
            } catch (RuntimeException $e) {
                Log::info('subscription.checkout_status: no se pudo consultar Payments Core', [
                    'reference' => $reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }
}
