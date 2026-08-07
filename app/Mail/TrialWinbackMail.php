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
 * Correo de reactivacion para un negocio que inicio la prueba pero no
 * regreso. Enviado por businesses:send-trial-winback, despues de extender la
 * prueba (ver Business::extendTrial()). Los headers X-Nexolu-Business-Id /
 * X-Nexolu-Email-Type hacen que LogSentEmail clasifique este envio
 * automaticamente en el historial de correos del panel de SuperAdmin.
 */
class TrialWinbackMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public string $ownerName,
        public int $trialDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Te extrañamos, '.$this->ownerName.' — tu prueba de Nexolú está reactivada',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.trial-winback',
            with: [
                'business_name' => $this->business->name,
                'owner_name' => $this->ownerName,
                'trial_days' => $this->trialDays,
                'trial_ends_date' => $this->business->trial_ends_at?->setTimezone('America/Bogota')->format('d/m/Y') ?? '',
                'app_url' => config('app.url'),
                'whatsapp_url' => 'https://wa.me/573239251072?text=Hola!+quiero+retomar+mi+prueba+en+Nexolu',
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->business->id,
                'X-Nexolu-Email-Type' => 'trial_winback',
            ],
        );
    }
}
