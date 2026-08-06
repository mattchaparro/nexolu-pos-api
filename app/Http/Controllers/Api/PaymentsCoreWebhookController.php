<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaasSubscriptionPayment;
use App\Models\SubscriptionCheckoutOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook publico del Nexolu Payments Core (servicio Python aparte, repo
 * nexolu-payments-core): unico punto por el que este POS se entera de que
 * un cobro de suscripcion cambio de estado. Nunca se confia en la respuesta
 * del navegador/widget - la fuente de verdad de un pago es este webhook,
 * firmado por el Core (ver docs/APP_INTEGRATION.md seccion 3 del Core).
 *
 * Responde 200 en cuanto la firma es valida y el evento quedo procesado -
 * el Core reintenta hasta 3 veces si no responde 2xx, asi que este handler
 * debe ser rapido e idempotente (ver approve()).
 */
class PaymentsCoreWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('payments_core.webhook: firma invalida', ['ip' => $request->ip()]);

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = $request->json()->all();
        $reference = (string) ($payload['reference'] ?? '');
        $event = (string) ($payload['event'] ?? '');

        if ($reference === '') {
            return response()->json(['error' => 'missing_reference'], 422);
        }

        match ($event) {
            'payment.approved' => $this->approve($reference, $payload),
            'payment.declined', 'payment.error' => $this->fail($reference, $payload),
            'payment.voided' => $this->void($reference, $payload),
            default => null, // payment.pending u otros: nada que hacer todavia.
        };

        return response()->json(['ok' => true]);
    }

    private function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('services.payments_core.webhook_secret');
        $timestamp = (string) $request->header('X-Nexolu-Timestamp');
        $signature = (string) $request->header('X-Nexolu-Signature');

        if ($secret === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function approve(string $reference, array $payload): void
    {
        DB::transaction(function () use ($reference, $payload) {
            // lockForUpdate + status=pending como guarda de idempotencia: un
            // reintento del Core para la misma referencia ya no encuentra la
            // orden en pending (paso a confirmed) y no vuelve a activar nada.
            // El lock cierra ademas la ventana de carrera que el legacy
            // dejaba abierta entre dos webhooks casi simultaneos.
            $order = SubscriptionCheckoutOrder::where('order_key', $reference)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $order) {
                Log::info('payments_core.webhook: orden no encontrada o ya procesada', ['reference' => $reference]);

                return;
            }

            $order->update([
                'status' => 'confirmed',
                'provider_order_id' => $payload['provider_transaction_id'] ?? $payload['transaction_id'] ?? null,
                'confirmed_at' => now(),
                'payload' => $payload,
            ]);

            $business = $order->business;
            $business->activate($order->subscription_days);

            SaasSubscriptionPayment::create([
                'business_id' => $business->id,
                'amount_cop' => $order->amount_cop,
                'period_label' => now()->format('Y-m'),
                'paid_at' => now()->toDateString(),
                'payment_method' => $order->provider,
                'notes' => 'Pago automatico via Payments Core - Transaccion: '.($payload['provider_transaction_id'] ?? $payload['transaction_id'] ?? ''),
                'days_granted' => $order->subscription_days,
                'recorded_by_user_id' => null,
            ]);

            Log::info('payments_core.webhook: suscripcion activada', [
                'business_id' => $business->id,
                'days' => $order->subscription_days,
                'transaction_id' => $payload['transaction_id'] ?? null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fail(string $reference, array $payload): void
    {
        // A diferencia del legacy (que dejaba la orden en pending para
        // siempre en un rechazo), aca se marca failed explicitamente: el
        // estado ya existe en la columna, no hay razon para no usarlo.
        $order = SubscriptionCheckoutOrder::where('order_key', $reference)
            ->where('status', 'pending')
            ->first();

        if (! $order) {
            return;
        }

        $order->update(['status' => 'failed', 'payload' => $payload]);

        Log::info('payments_core.webhook: pago fallido', ['reference' => $reference, 'status' => $payload['status'] ?? null]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function void(string $reference, array $payload): void
    {
        $order = SubscriptionCheckoutOrder::where('order_key', $reference)
            ->where('status', 'pending')
            ->first();

        if (! $order) {
            return;
        }

        $order->update(['status' => 'cancelled', 'payload' => $payload]);
    }
}
