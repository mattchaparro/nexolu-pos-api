<?php

namespace App\Services;

use App\Mail\OnlineOrderStatusMail;
use App\Models\Order;
use App\Support\ChannelPhone;
use Illuminate\Support\Facades\Log;

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
    public function __construct(private CommsNotificationService $comms) {}

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

    /**
     * Manda WhatsApp y correo en UNA sola transaccion contra el Core.
     *
     * Con dos llamadas separadas, si una sale y la otra no, el comprador
     * recibe la mitad del aviso y el Core las cuenta como envios sueltos
     * que nadie relaciona.
     */
    private function notify(Order $order, string $templateKey): void
    {
        try {
            $channels = [];
            $to = [];

            $phone = ChannelPhone::normalize((string) $order->customer_phone);
            $template = $this->whatsappTemplate($order, $templateKey);
            if ($phone !== null && $template !== null) {
                $channels[] = 'whatsapp';
                $to['whatsapp'] = $phone;
            }

            $email = filter_var((string) $order->customer_email, FILTER_VALIDATE_EMAIL)
                ? (string) $order->customer_email
                : null;
            if ($email !== null) {
                $channels[] = 'email';
                $to['email'] = $email;
            }

            if ($channels === []) {
                return;
            }

            $mail = new OnlineOrderStatusMail($order->loadMissing('items'), $templateKey, $this->storeName($order));

            $this->comms->send(
                channels: $channels,
                to: $to,
                subject: $email !== null ? $mail->envelope()->subject : null,
                // El HTML lo sigue armando el Mailable: la plantilla vive en
                // un solo lugar, se mande por donde se mande.
                html: $email !== null ? $mail->render() : null,
                whatsappTemplate: $template,
                businessId: $order->business_id,
                reference: $templateKey,
            );
        } catch (\Throwable $e) {
            Log::warning('online_order.buyer_notification_failed', [
                'order_id' => $order->id,
                'template' => $templateKey,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * La plantilla lista para el Core, o null si no hay ninguna aprobada
     * todavia -- en ese caso el aviso sale solo por correo.
     *
     * @return array<string, mixed>|null
     */
    private function whatsappTemplate(Order $order, string $templateKey): ?array
    {
        $template = config("services.whatsapp.templates.{$templateKey}");
        if (! filled($template['name'] ?? null)) {
            return null;
        }

        return [
            'name' => $template['name'],
            'language' => $template['lang'] ?? 'es_CO',
            'components' => $this->components($order, $templateKey),
        ];
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

    private function storeName(Order $order): string
    {
        $business = $order->business;

        return $business?->storeSettings()->withoutGlobalScopes()->value('store_name')
            ?: (string) ($business?->name ?? 'la tienda');
    }
}
