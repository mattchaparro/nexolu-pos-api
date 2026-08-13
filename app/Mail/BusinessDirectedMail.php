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
 * Correo de asunto y cuerpo libres que un superadmin redacta y manda a un
 * negocio puntual (ver BusinessCommunicationService::send()) - a diferencia
 * del resto de Mailables de esta app, que tienen contenido fijo. Los headers
 * X-Nexolu-Business-Id/X-Nexolu-Email-Type hacen que LogSentEmail lo
 * clasifique en el historial de Comunicaciones de SuperAdmin igual que
 * cualquier otro correo.
 */
class BusinessDirectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public string $emailSubject,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.business-directed',
            with: [
                'business_name' => $this->business->name,
                'body' => $this->body,
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->business->id,
                'X-Nexolu-Email-Type' => 'superadmin_directed',
            ],
        );
    }
}
