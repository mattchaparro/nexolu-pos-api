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
 * "No esta el medio de pago que necesito" desde Ajustes > Medios de pago -
 * ver PaymentMethodSupportRequestController. Va al buzon de soporte de
 * Nexolu (config('mail.support_address')), no a un negocio ni a un
 * superadmin: por eso el asunto es fijo, pedido explicitamente por el
 * negocio - "Solicitud de añadir medio de pago" - para que el equipo pueda
 * filtrarlo facil en su bandeja.
 */
class PaymentMethodSupportRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public User $requester,
        public string $requestMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Solicitud de añadir medio de pago',
            replyTo: [$this->requester->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-method-support-request',
            with: [
                'business_name' => $this->business->name,
                'business_id' => $this->business->id,
                'requester_name' => $this->requester->fullName(),
                'requester_email' => $this->requester->email,
                'request_message' => $this->requestMessage,
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->business->id,
                'X-Nexolu-Email-Type' => 'payment_method_support_request',
            ],
        );
    }
}
