<?php

namespace App\Mail;

use App\Models\Business;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso al comerciante de que entro un pedido por su tienda online.
 *
 * Es el unico canal de aviso que funciona sin tramite: WhatsApp exige una
 * plantilla aprobada por Meta (ver AppointmentWhatsappNotifier) y esa
 * aprobacion no depende de este repo. La bandeja del POS ya avisa con un
 * contador, pero solo si hay alguien mirando la pantalla.
 *
 * Los headers X-Nexolu-* hacen que LogSentEmail lo clasifique en el
 * historial de correos de SuperAdmin, igual que el resto.
 */
class NewOnlineOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public Order $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Nuevo pedido #{$this->order->number} - {$this->business->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-online-order',
            with: [
                'business_name' => $this->business->name,
                'order' => $this->order,
                'items' => $this->order->items,
                'app_url' => config('app.url'),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->business->id,
                'X-Nexolu-Email-Type' => 'new_online_order',
            ],
        );
    }
}
