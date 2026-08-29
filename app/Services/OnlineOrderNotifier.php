<?php

namespace App\Services;

use App\Mail\OnlineOrderStatusMail;
use App\Models\Order;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Support\ChannelPhone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Avisos al COMPRADOR sobre su pedido.
 *
 * Le escribe a su telefono y a su correo, no a un usuario del negocio con
 * canal vinculado -- por eso no usa WhatsAppRecipients, que resuelve
 * destinatarios del lado del comercio (mismo criterio que
 * AppointmentWhatsappNotifier).
 *
 * Los dos canales son independientes y ninguno es obligatorio: sin plantilla
 * aprobada en Meta no hay WhatsApp, y sin correo en el pedido no hay correo.
 * Comprar como invitado significa que el comprador puede haber dejado solo el
 * telefono.
 *
 * Nada de esto puede tumbar un pedido: se llama despues de que el cambio de
 * estado ya se guardo, y cualquier fallo se registra y sigue.
 */
class OnlineOrderNotifier
{
    public function __construct(private MessagingChannel $whatsapp) {}

    public function sendReceived(Order $order): void
    {
        $this->notify($order, 'pedido_recibido');
    }

    public function sendConfirmed(Order $order): void
    {
        $this->notify($order, 'pedido_confirmado');
    }

    public function sendShipped(Order $order): void
    {
        $this->notify($order, 'pedido_enviado');
    }

    /** Que estados le importan al comprador. Los demas son ruido interno. */
    public function sendForStatus(Order $order, string $status): void
    {
        match ($status) {
            Order::STATUS_CONFIRMED => $this->sendConfirmed($order),
            Order::STATUS_SHIPPED => $this->sendShipped($order),
            default => null,
        };
    }

    private function notify(Order $order, string $templateKey): void
    {
        try {
            $this->sendWhatsapp($order, $templateKey);
            $this->sendEmail($order, $templateKey);
        } catch (\Throwable $e) {
            Log::warning('online_order.buyer_notification_failed', [
                'order_id' => $order->id,
                'template' => $templateKey,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function sendWhatsapp(Order $order, string $templateKey): bool
    {
        $template = config("services.whatsapp.templates.{$templateKey}");
        if (! filled($template['name'] ?? null)) {
            return false;
        }

        $phone = ChannelPhone::normalize((string) $order->customer_phone);
        if ($phone === null) {
            return false;
        }

        return $this->whatsapp->sendTemplate(
            $phone,
            $template['name'],
            $template['lang'] ?? 'es_CO',
            $this->components($order, $templateKey),
            $order->business_id,
            $templateKey,
        );
    }

    /**
     * Variables de la plantilla, en el orden documentado en
     * config/services.php. El total solo va en el primer aviso: en los
     * demas ya no aporta y obligaria a plantillas distintas por moneda.
     *
     * @return list<array<string, mixed>>
     */
    private function components(Order $order, string $templateKey): array
    {
        $parameters = [
            ['type' => 'text', 'text' => mb_substr((string) $order->customer_name, 0, 60)],
            ['type' => 'text', 'text' => mb_substr((string) $this->storeName($order), 0, 60)],
            ['type' => 'text', 'text' => '#'.$order->number],
        ];

        if ($templateKey === 'pedido_recibido') {
            $parameters[] = ['type' => 'text', 'text' => '$'.number_format((float) $order->total, 0, ',', '.')];
        }

        return [['type' => 'body', 'parameters' => $parameters]];
    }

    private function sendEmail(Order $order, string $templateKey): bool
    {
        if (! filter_var((string) $order->customer_email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        Mail::to($order->customer_email)->queue(
            new OnlineOrderStatusMail($order->loadMissing('items'), $templateKey, $this->storeName($order))
        );

        return true;
    }

    private function storeName(Order $order): string
    {
        $business = $order->business;

        return $business?->storeSettings()->withoutGlobalScopes()->value('store_name')
            ?: (string) ($business?->name ?? 'la tienda');
    }
}
