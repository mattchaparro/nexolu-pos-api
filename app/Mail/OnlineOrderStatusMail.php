<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso al COMPRADOR sobre su pedido: recibido, confirmado o enviado.
 *
 * Un solo Mailable con tres textos en vez de tres clases: el cuerpo es el
 * mismo pedido, lo unico que cambia es el encabezado y una frase. Tres
 * plantillas casi iguales se desincronizan en cuanto alguien toca una.
 */
class OnlineOrderStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var array<string, array{subject: string, eyebrow: string, headline: string, body: string}> */
    private const COPY = [
        'pedido_recibido' => [
            'subject' => 'Recibimos tu pedido',
            'eyebrow' => 'Pedido recibido',
            'headline' => '¡Gracias por tu compra!',
            'body' => 'Ya tenemos tu pedido. Te avisamos apenas la tienda lo confirme.',
        ],
        'pedido_confirmado' => [
            'subject' => 'Tu pedido está confirmado',
            'eyebrow' => 'Pedido confirmado',
            'headline' => 'Estamos preparando tu pedido',
            'body' => 'La tienda confirmó tu pedido y ya lo está alistando.',
        ],
        'pedido_enviado' => [
            'subject' => 'Tu pedido va en camino',
            'eyebrow' => 'Pedido enviado',
            'headline' => 'Tu pedido va en camino',
            'body' => 'Ya salió hacia la dirección que nos diste.',
        ],
    ];

    public function __construct(
        public Order $order,
        public string $templateKey,
        public string $storeName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->copy()['subject'].' · '.$this->storeName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.online-order-status',
            with: [
                'copy' => $this->copy(),
                'order' => $this->order,
                'items' => $this->order->items,
                'store_name' => $this->storeName,
                'tracking_url' => $this->trackingUrl(),
            ],
        );
    }

    /** @return array{subject: string, eyebrow: string, headline: string, body: string} */
    private function copy(): array
    {
        return self::COPY[$this->templateKey] ?? self::COPY['pedido_recibido'];
    }

    /**
     * La pagina de seguimiento. Es la unica llave que tiene un comprador sin
     * cuenta para volver a su pedido, asi que va en todos los avisos.
     */
    private function trackingUrl(): string
    {
        $base = rtrim((string) config('app.storefront_url'), '/');
        $slug = $this->order->business?->slug;

        return "{$base}/{$slug}/pedido/{$this->order->public_token}";
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->order->business_id,
                'X-Nexolu-Email-Type' => 'online_order_status',
            ],
        );
    }
}
