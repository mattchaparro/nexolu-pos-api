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

/**
 * Resultado de un cobro de suscripcion, para el admin del negocio. Enviado
 * desde PaymentsCoreWebhookController al procesar un evento payment.approved
 * (succeeded=true) o payment.declined/payment.error (succeeded=false). Se
 * encola (Mail::queue) en vez de enviarse sincrono: el Core espera una
 * respuesta rapida del webhook, no debe esperar a que salga el correo.
 */
class SubscriptionPaymentResultMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $admin,
        public Business $business,
        public bool $succeeded,
        public int $amountCop,
        public ?int $daysGranted = null,
        public ?string $failureStatus = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->succeeded
                ? '✅ Pago confirmado — '.$this->business->name
                : '❌ Tu pago no fue procesado — '.$this->business->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-payment-result',
            with: [
                'admin_name' => $this->admin->name,
                'business_name' => $this->business->name,
                'succeeded' => $this->succeeded,
                'amount_cop' => $this->amountCop,
                'days_granted' => $this->daysGranted,
                'failure_status' => $this->failureStatus,
                'app_url' => config('app.url'),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->business->id,
                'X-Nexolu-Email-Type' => $this->succeeded ? 'subscription_payment_confirmed' : 'subscription_payment_failed',
            ],
        );
    }
}
