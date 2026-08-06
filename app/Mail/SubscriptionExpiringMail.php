<?php

namespace App\Mail;

use App\Models\Business;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Aviso de que el trial o el periodo pago de un negocio esta por vencer.
 * Enviado por los comandos subscriptions:notify-expiring (suscripciones
 * pagas) y trials:notify-expiring (trials, con aviso a 3 y a 1 dia). Los
 * headers X-Nexolu-Business-Id / X-Nexolu-Email-Type hacen que LogSentEmail
 * clasifique este envio automaticamente en el historial de correos del
 * panel de SuperAdmin; $emailType tambien es lo que trials:notify-expiring
 * usa para no reenviar el mismo aviso (3d o 1d) dentro de una ventana corta.
 */
class SubscriptionExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $owner,
        public Business $business,
        public Carbon $expiresAt,
        public bool $isPaidSubscription,
        public ?int $daysLeft = null,
        public string $emailType = 'subscription_expiring',
    ) {}

    public function envelope(): Envelope
    {
        $subject = match (true) {
            ! $this->isPaidSubscription && $this->daysLeft === 1 => 'Tu prueba gratuita de Nexolú termina mañana',
            $this->isPaidSubscription => 'Tu suscripcion de Nexolú vence pronto',
            default => 'Tu prueba gratuita de Nexolú vence pronto',
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-expiring',
            with: [
                'owner_name' => $this->owner->name,
                'business_name' => $this->business->name,
                'expires_at' => $this->expiresAt,
                'is_paid_subscription' => $this->isPaidSubscription,
                'app_url' => config('app.url'),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->business->id,
                'X-Nexolu-Email-Type' => $this->emailType,
            ],
        );
    }
}
