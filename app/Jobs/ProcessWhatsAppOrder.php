<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\User;
use App\Models\WhatsappOrder;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\WhatsApp\WhatsAppOrderConverter;
use App\Support\WhatsAppRecipients;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Convierte un carrito de WhatsApp (webhook `order` de Meta) en una venta
 * del POS - la decision acordada es AUTO CON FALLBACK: si productos, stock
 * y negocio validan, se abre una cuenta (sin pago, kitchen pending) y el
 * cliente recibe confirmacion; si algo no valida, el pedido queda
 * `pending_review` en la bandeja de whatsapp_orders, se les avisa a los
 * admins del negocio y al cliente se le dice que se esta confirmando -
 * nunca un silencio ni un pedido perdido.
 *
 * La conversion en si (mapeo por sku, precio del servidor, cliente por
 * telefono, openTab) vive en WhatsAppOrderConverter, compartida con la
 * bandeja manual. Idempotencia dura por wamid (unique en whatsapp_orders),
 * ademas del Cache::add del dispatcher que expira a las 6h.
 */
class ProcessWhatsAppOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $order  el objeto `order` crudo de Meta
     */
    public function __construct(
        public readonly string $from,
        public readonly array $order,
        public readonly string $wamid,
        public readonly ?string $businessId = null,
        public readonly ?string $profileName = null,
    ) {}

    public function handle(WhatsAppOrderConverter $converter, MessagingChannel $whatsapp): void
    {
        $business = $this->resolveBusiness();
        if ($business === null) {
            Log::warning('whatsapp_order: no se pudo resolver el negocio', [
                'wamid' => $this->wamid,
                'business_header' => $this->businessId,
            ]);

            return;
        }

        $whatsappOrder = WhatsappOrder::query()->firstOrCreate(
            ['wamid' => $this->wamid],
            [
                'business_id' => $business->id,
                'phone' => $this->from,
                'customer_name' => $this->profileName,
                'raw_order' => $this->order,
                'status' => WhatsappOrder::STATUS_PENDING_REVIEW,
            ],
        );

        if (! $whatsappOrder->wasRecentlyCreated) {
            return;
        }

        $user = $this->resolveSystemUser($business);
        if ($user === null) {
            // Mismo trato que cualquier fallback: el cliente NUNCA queda en
            // silencio, y el pedido queda en la bandeja con su motivo.
            $this->markPendingReview(
                $whatsapp,
                $business,
                $whatsappOrder,
                'El negocio no tiene un admin activo para atribuir la venta.',
            );

            return;
        }

        // Los jobs de cola no tienen sesion HTTP y BelongsToBusiness la
        // necesita - mismo patron que ProcessWhatsAppInbound.
        Auth::setUser($user);

        try {
            $sale = $converter->convert($whatsappOrder, $user);

            $whatsapp->sendText(
                $this->from,
                '✅ ¡Recibimos tu pedido! Total: $'.number_format((float) $sale->total, 0, ',', '.')
                    .'. Te confirmamos apenas esté listo. Si quieres agregar una nota, respóndenos por aquí.',
                $business->id,
                'pedido_whatsapp',
            );
        } catch (ValidationException $e) {
            $this->markPendingReview($whatsapp, $business, $whatsappOrder, collect($e->errors())->flatten()->implode(' '));
        } catch (Throwable $e) {
            Log::error('whatsapp_order: error inesperado', ['wamid' => $this->wamid, 'error' => $e->getMessage()]);
            $this->markPendingReview($whatsapp, $business, $whatsappOrder, 'Error inesperado: '.$e->getMessage());
        }
    }

    /**
     * Numero propio: Connect ya resolvio el negocio (header
     * X-Nexolu-Business-Id). Numero compartido: el prefijo del retailer_id
     * (b{business}-{sku}) lo trae.
     */
    private function resolveBusiness(): ?Business
    {
        if ($this->businessId !== null && ctype_digit($this->businessId)) {
            return Business::find((int) $this->businessId);
        }

        $first = $this->order['product_items'][0]['product_retailer_id'] ?? '';
        if (preg_match('/^b(\d+)-/', (string) $first, $m) === 1) {
            return Business::find((int) $m[1]);
        }

        return null;
    }

    /**
     * El admin vinculado a WhatsApp si existe (el mismo que recibe las
     * alertas del negocio); si no, cualquier admin activo del negocio.
     */
    private function resolveSystemUser(Business $business): ?User
    {
        $linked = WhatsAppRecipients::linkedAdmins($business)->first();
        if ($linked?->user !== null && $linked->user->is_active) {
            return $linked->user;
        }

        return $business->users()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->first();
    }

    private function markPendingReview(
        MessagingChannel $whatsapp,
        Business $business,
        WhatsappOrder $whatsappOrder,
        string $reason,
    ): void {
        $whatsappOrder->update([
            'status' => WhatsappOrder::STATUS_PENDING_REVIEW,
            'error' => $reason,
        ]);

        $whatsapp->sendText(
            $this->from,
            '📝 Recibimos tu pedido y lo estamos confirmando. En un momento te escribimos.',
            $business->id,
            'pedido_whatsapp',
        );

        $this->notifyBusiness($whatsapp, $business, $whatsappOrder);
    }

    private function notifyBusiness(MessagingChannel $whatsapp, Business $business, WhatsappOrder $whatsappOrder): void
    {
        $lines = collect($this->order['product_items'] ?? [])
            ->map(fn ($l) => ($l['quantity'] ?? 1).'x '.($l['product_retailer_id'] ?? '?'))
            ->implode(', ');

        foreach (WhatsAppRecipients::linkedAdmins($business) as $identity) {
            $whatsapp->sendText(
                $identity->external_id,
                "🛒 Pedido de WhatsApp por revisar de {$this->from}: {$lines}. "
                    .'Motivo: '.($whatsappOrder->error ?: 'revision manual.'),
                $business->id,
                'pedido_whatsapp',
            );
        }
    }
}
