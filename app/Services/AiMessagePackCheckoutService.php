<?php

namespace App\Services;

use App\Models\AiMessagePackCheckoutOrder;
use App\Models\Business;
use App\Models\User;
use App\Support\AiQuotaSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Compra self-serve de un paquete de mensajes de IA, mismo flujo que
 * SubscriptionService::initiateCheckout()/checkoutStatus(): orden pendiente +
 * intent en Nexolu Payments Core, confirmada despues por webhook (ver
 * PaymentsCoreWebhookController::approve() y AiMessagePackService::credit()).
 */
class AiMessagePackCheckoutService
{
    public function __construct(private PaymentsCoreService $paymentsCore) {}

    /**
     * Mismo criterio que SubscriptionService::initiateCheckout(): la orden
     * arranca con un `order_key` placeholder porque el Core es quien genera
     * la `reference` real, y se pisa apenas responde, antes de devolver
     * nada al frontend.
     *
     * @return array{order_key: string, amount_cop: int, checkout: array<string, mixed>}
     */
    public function initiateCheckout(Business $business, User $user, string $redirectUrl): array
    {
        $messages = AiQuotaSettings::packSize();
        $priceCop = AiQuotaSettings::packPriceCop();

        $order = AiMessagePackCheckoutOrder::create([
            'business_id' => $business->id,
            'order_key' => 'pending-'.(string) Str::ulid(),
            'messages' => $messages,
            'price_cop' => $priceCop,
            'status' => 'pending',
            'provider' => 'wompi',
            'created_by_user_id' => $user->id,
        ]);

        try {
            $intent = $this->paymentsCore->createIntent(
                amountCop: $priceCop,
                customer: ['email' => $user->email, 'full_name' => $user->name],
                redirectUrl: $redirectUrl,
                metadata: ['business_id' => $business->id, 'ai_message_pack_messages' => $messages],
            );
        } catch (\Throwable $e) {
            // Igual que SubscriptionService: el Core nunca registro este
            // intent, no queda nada que un webhook pueda confirmar despues.
            $order->delete();

            throw $e;
        }

        $order->update([
            'order_key' => $intent['reference'],
            'provider' => $intent['provider'] ?? $order->provider,
        ]);

        return [
            'order_key' => $order->order_key,
            'amount_cop' => $priceCop,
            'checkout' => $intent['checkout'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkoutStatus(Business $business, string $reference): array
    {
        $order = AiMessagePackCheckoutOrder::where('business_id', $business->id)
            ->where('order_key', $reference)
            ->firstOrFail();

        $result = [
            'order_key' => $order->order_key,
            'status' => $order->status,
            'amount_cop' => $order->price_cop,
            'messages' => $order->messages,
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
        ];

        if ($order->status === 'pending') {
            try {
                $transaction = $this->paymentsCore->getTransaction($reference);
                $result['provider_status'] = $transaction['status'] ?? null;
            } catch (RuntimeException $e) {
                Log::info('ai_message_pack.checkout_status: no se pudo consultar Payments Core', [
                    'reference' => $reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }
}
