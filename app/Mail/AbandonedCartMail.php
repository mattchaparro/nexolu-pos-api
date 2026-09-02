<?php

namespace App\Mail;

use App\Models\Business;
use App\Models\StoreCart;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * "Dejaste algo en el carrito".
 *
 * Se manda UNA sola vez por carrito (ver AbandonedCartReminder): quien no
 * compro no quiere una segunda insistencia, y el dominio que se quema con
 * las marcas de spam es el del comercio.
 *
 * El enlace de recuperacion viaja firmado y con vencimiento: un correo se
 * reenvia, se archiva y termina en cualquier parte, asi que el token del
 * carrito no puede ser una llave permanente dentro de el.
 */
class AbandonedCartMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public StoreCart $cart,
        public Business $business,
        public string $recoveryUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Dejaste algo en tu carrito · '.$this->storeName(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.abandoned-cart',
            with: [
                'store_name' => $this->storeName(),
                'greeting_name' => $this->cart->contact_name,
                'items' => $this->cart->items ?? [],
                'subtotal' => (float) $this->cart->subtotal,
                'recovery_url' => $this->recoveryUrl,
            ],
        );
    }

    private function storeName(): string
    {
        $settings = $this->business->storeSettings()->withoutGlobalScope('business')->first();

        return (string) ($settings?->store_name ?: $this->business->name);
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->business->id,
                'X-Nexolu-Email-Type' => 'abandoned_cart',
            ],
        );
    }
}
