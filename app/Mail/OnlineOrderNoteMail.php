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
 * Un mensaje que el comerciante le escribe al comprador sobre su pedido.
 *
 * Distinto de OnlineOrderStatusMail: ahi el texto lo pone el sistema y el
 * pedido cambio de estado; aca lo escribe una persona y el pedido no cambio
 * -- "se nos acabo el azul, ¿te sirve el negro?".
 */
class OnlineOrderNoteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
        public string $body,
        public string $storeName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Sobre tu pedido #{$this->order->number} · {$this->storeName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.online-order-note',
            with: [
                'order' => $this->order,
                'body' => $this->body,
                'store_name' => $this->storeName,
                'tracking_url' => $this->trackingUrl(),
            ],
        );
    }

    /** La unica llave que tiene un comprador sin cuenta para volver a su pedido. */
    private function trackingUrl(): string
    {
        $base = rtrim((string) config('app.storefront_url'), '/');

        return "{$base}/{$this->order->business?->slug}/pedido/{$this->order->public_token}";
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->order->business_id,
                'X-Nexolu-Email-Type' => 'online_order_note',
            ],
        );
    }
}
