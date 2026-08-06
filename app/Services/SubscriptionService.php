<?php

namespace App\Services;

use App\Models\Business;
use App\Models\SubscriptionCheckoutOrder;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    public function __construct(private PaymentsCoreService $paymentsCore) {}

    /**
     * Crea una orden pendiente y, con esa referencia, un intent de cobro en
     * el Payments Core. La orden se crea ANTES de llamar al Core (igual que
     * el legacy con Wompi): es lo que permite correlacionar el webhook de
     * confirmacion despues, y no depende de que la llamada al Core tenga
     * exito para existir.
     *
     * @return array{order_key: string, amount_cop: int, checkout: array<string, mixed>}
     */
    public function initiateCheckout(Business $business, User $user, string $redirectUrl): array
    {
        $amount = $business->monthlyPriceCop();

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
        ];
    }
}
