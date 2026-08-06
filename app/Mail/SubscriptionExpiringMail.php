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
 * Enviado por el comando subscriptions:notify-expiring. Los headers
 * X-Nexolu-Business-Id / X-Nexolu-Email-Type hacen que LogSentEmail
 * clasifique este envio automaticamente en el historial de correos del
 * panel de SuperAdmin.
 */
class SubscriptionExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $owner,
        public Business $business,
        public Carbon $expiresAt,
        public bool $isPaidSubscription,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isPaidSubscription
                ? 'Tu suscripcion de Nexolú vence pronto'
                : 'Tu prueba gratuita de Nexolú vence pronto',
        );
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
                'X-Nexolu-Email-Type' => 'subscription_expiring',
            ],
        );
    }
}
