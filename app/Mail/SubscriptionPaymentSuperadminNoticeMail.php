<?php

namespace App\Mail;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a cada superadmin de que un cobro de suscripcion se resolvio
 * (aprobado o rechazado), con el detalle de la transaccion para auditoria
 * rapida. Enviado una vez por superadmin desde PaymentsCoreWebhookController
 * - ver SubscriptionPaymentResultMail para el aviso equivalente al admin
 * del negocio.
 */
class SubscriptionPaymentSuperadminNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public bool $succeeded,
        public int $amountCop,
        public string $reference,
        public ?string $providerTransactionId = null,
        public ?int $daysGranted = null,
        public ?string $failureStatus = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->succeeded
                ? '✅ Pago aprobado — '.$this->business->name
                : '❌ Pago fallido — '.$this->business->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-payment-superadmin-notice',
            with: [
                'business_name' => $this->business->name,
                'business_id' => $this->business->id,
                'succeeded' => $this->succeeded,
                'amount_cop' => $this->amountCop,
                'reference' => $this->reference,
                'provider_transaction_id' => $this->providerTransactionId,
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
                'X-Nexolu-Email-Type' => $this->succeeded ? 'subscription_payment_confirmed_superadmin' : 'subscription_payment_failed_superadmin',
            ],
        );
    }
}
