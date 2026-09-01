<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SubscriptionPaymentResultMail;
use App\Mail\SubscriptionPaymentSuperadminNoticeMail;
use App\Models\AiMessagePackCheckoutOrder;
use App\Models\BusinessPaymentGateway;
use App\Models\Order;
use App\Models\SaasSubscriptionPayment;
use App\Models\SubscriptionCheckoutOrder;
use App\Models\TerminalCharge;
use App\Models\User;
use App\Services\AiMessagePackService;
use App\Services\OnlineOrderPaymentService;
use App\Services\TerminalChargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Webhook publico del Nexolu Payments Core (servicio Python aparte, repo
 * nexolu-payments-core): unico punto por el que este POS se entera de que un
 * cobro (suscripcion o paquete de mensajes de IA) cambio de estado. Nunca se
 * confia en la respuesta del navegador/widget - la fuente de verdad de un
 * pago es este webhook, firmado por el Core (ver docs/APP_INTEGRATION.md
 * seccion 3 del Core).
 *
 * Responde 200 en cuanto la firma es valida y el evento quedo procesado -
 * el Core reintenta hasta 3 veces si no responde 2xx, asi que este handler
 * debe ser rapido e idempotente (ver approveSubscription()/approvePack()).
 *
 * Una referencia solo puede pertenecer a una de las dos tablas de ordenes
 * (subscription_checkout_orders o ai_message_pack_checkout_orders): el
 * `order_key` de ambas es la `reference` que genera el Payments Core
 * (globalmente unica alla), no algo que este repo arme, asi que nunca hay
 * colision entre las dos tablas. Basta con probar la de suscripcion primero
 * y caer a la de paquetes si no aparece.
 */
class PaymentsCoreWebhookController extends Controller
{
    public function __construct(private AiMessagePackService $packs) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $reference = (string) ($payload['reference'] ?? '');
        $event = (string) ($payload['event'] ?? '');

        if ($reference === '') {
            return response()->json(['error' => 'missing_reference'], 422);
        }

        // La referencia se lee ANTES de verificar la firma, y esta bien: no
        // concede nada por si sola, solo dice CON QUE SECRETO hay que
        // verificar. Desde que cada negocio tiene su propia pasarela ya no
        // hay un unico secreto: un pedido de la tienda lo firma la
        // integracion de ESE negocio, no la de Nexolu.
        $secret = $this->resolveWebhookSecret($reference);
        if ($secret === null || ! $this->hasValidSignature($request, $secret)) {
            Log::warning('payments_core.webhook: firma invalida', ['ip' => $request->ip()]);

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        match ($event) {
            'payment.approved' => $this->approve($reference, $payload),
            'payment.declined', 'payment.error' => $this->fail($reference, $payload),
            'payment.voided' => $this->void($reference, $payload),
            default => null, // payment.pending u otros: nada que hacer todavia.
        };

        return response()->json(['ok' => true]);
    }

    /**
     * Con que secreto se firma esta referencia.
     *
     * Un pedido de la tienda online lo firma la integracion del NEGOCIO
     * (cobra el, la plata es suya). Una suscripcion o un pack de IA los
     * firma la integracion de Nexolu (cobramos nosotros). Usar el secreto
     * equivocado no es solo un 401: seria aceptar como valido un evento
     * firmado por otra integracion.
     */
    private function resolveWebhookSecret(string $reference): ?string
    {
        $businessId = Order::withoutGlobalScopes()->where('payment_reference', $reference)->value('business_id')
            ?? TerminalCharge::withoutGlobalScopes()->where('reference', $reference)->value('business_id');

        if ($businessId !== null) {
            $gateway = BusinessPaymentGateway::withoutGlobalScopes()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->first();

            return $gateway?->webhook_secret;
        }

        return (string) config('services.payments_core.webhook_secret') ?: null;
    }

    private function hasValidSignature(Request $request, string $secret): bool
    {
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
        $terminalCharge = TerminalCharge::withoutGlobalScopes()->where('reference', $reference)->first();
        if ($terminalCharge !== null) {
            // Aprobado, pero SIN venta todavia: la crea la caja cuando lo
            // consume (ver TerminalChargeService::redeem). El cajero puede
            // seguir agregando items mientras el cliente paga.
            app(TerminalChargeService::class)->resolve($terminalCharge, TerminalCharge::STATUS_APPROVED);

            return;
        }

        $onlineOrder = Order::withoutGlobalScopes()->where('payment_reference', $reference)->first();
        if ($onlineOrder !== null) {
            app(OnlineOrderPaymentService::class)->approve($onlineOrder);

            return;
        }

        if (SubscriptionCheckoutOrder::where('order_key', $reference)->exists()) {
            $this->approveSubscription($reference, $payload);

            return;
        }

        $this->approvePack($reference, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function approveSubscription(string $reference, array $payload): void
    {
        // El envio de correos queda fuera de la transaccion a proposito: no
        // hay razon para retener el lock de la fila mientras se encolan.
        $order = DB::transaction(function () use ($reference, $payload) {
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

                return null;
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

            return $order;
        });

        if ($order) {
            $this->notifyPaymentResult($order, succeeded: true, payload: $payload);
        }
    }

    /**
     * Acredita un paquete de mensajes de IA. Mismo guard de idempotencia que
     * approveSubscription() (lockForUpdate + status=pending), sin correos: es
     * una compra dentro del producto, no un cobro que amerite avisar al
     * superadmin.
     *
     * @param  array<string, mixed>  $payload
     */
    private function approvePack(string $reference, array $payload): void
    {
        DB::transaction(function () use ($reference, $payload) {
            $order = AiMessagePackCheckoutOrder::where('order_key', $reference)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $order) {
                Log::info('payments_core.webhook: orden de paquete no encontrada o ya procesada', ['reference' => $reference]);

                return;
            }

            $order->update([
                'status' => 'confirmed',
                'provider_order_id' => $payload['provider_transaction_id'] ?? $payload['transaction_id'] ?? null,
                'confirmed_at' => now(),
                'payload' => $payload,
            ]);

            $this->packs->credit(
                $order->business,
                $order->messages,
                $order->price_cop,
                $order->created_by_user_id,
                'Compra self-serve via Payments Core - Transaccion: '.($payload['provider_transaction_id'] ?? $payload['transaction_id'] ?? ''),
            );

            Log::info('payments_core.webhook: paquete de mensajes de IA acreditado', [
                'business_id' => $order->business_id,
                'messages' => $order->messages,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fail(string $reference, array $payload): void
    {
        $terminalCharge = TerminalCharge::withoutGlobalScopes()->where('reference', $reference)->first();
        if ($terminalCharge !== null) {
            app(TerminalChargeService::class)->resolve(
                $terminalCharge,
                TerminalCharge::STATUS_DECLINED,
                'La tarjeta fue rechazada en el datáfono.',
            );

            return;
        }

        if (SubscriptionCheckoutOrder::where('order_key', $reference)->exists()) {
            $this->failSubscription($reference, $payload);

            return;
        }

        $this->failPack($reference, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function failSubscription(string $reference, array $payload): void
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

        $this->notifyPaymentResult($order, succeeded: false, payload: $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function failPack(string $reference, array $payload): void
    {
        $order = AiMessagePackCheckoutOrder::where('order_key', $reference)->where('status', 'pending')->first();

        if (! $order) {
            return;
        }

        $order->update(['status' => 'failed', 'payload' => $payload]);

        Log::info('payments_core.webhook: pago de paquete fallido', ['reference' => $reference, 'status' => $payload['status'] ?? null]);
    }

    /**
     * Encola el aviso al admin del negocio y a cada superadmin. Se encola
     * (Mail::queue), no se envia sincrono - el Core espera una respuesta
     * rapida del webhook (ver docs/APP_INTEGRATION.md seccion 3 del Core).
     * Un fallo al encolar nunca debe tumbar el procesamiento del webhook,
     * por eso cada envio va en su propio try/catch.
     *
     * @param  array<string, mixed>  $payload
     */
    private function notifyPaymentResult(SubscriptionCheckoutOrder $order, bool $succeeded, array $payload): void
    {
        $business = $order->business;
        $providerTransactionId = $payload['provider_transaction_id'] ?? $payload['transaction_id'] ?? null;
        $failureStatus = $succeeded ? null : (string) ($payload['status'] ?? 'rechazado');
        $daysGranted = $succeeded ? $order->subscription_days : null;

        $admin = User::where('business_id', $business->id)->where('is_business_owner', true)->first();
        if ($admin) {
            try {
                Mail::to($admin->email)->queue(new SubscriptionPaymentResultMail(
                    $admin, $business, $succeeded, $order->amount_cop, $daysGranted, $failureStatus,
                ));
            } catch (Throwable $e) {
                Log::warning('payments_core.webhook: error notificando admin del negocio', ['error' => $e->getMessage()]);
            }
        }

        foreach (User::role('superadmin')->get() as $superadmin) {
            try {
                Mail::to($superadmin->email)->queue(new SubscriptionPaymentSuperadminNoticeMail(
                    $business, $succeeded, $order->amount_cop, $order->order_key, $providerTransactionId, $daysGranted, $failureStatus,
                ));
            } catch (Throwable $e) {
                Log::warning('payments_core.webhook: error notificando superadmin', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function void(string $reference, array $payload): void
    {
        $order = SubscriptionCheckoutOrder::where('order_key', $reference)
            ->where('status', 'pending')
            ->first();

        if ($order) {
            $order->update(['status' => 'cancelled', 'payload' => $payload]);

            return;
        }

        AiMessagePackCheckoutOrder::where('order_key', $reference)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'payload' => $payload]);
    }
}
