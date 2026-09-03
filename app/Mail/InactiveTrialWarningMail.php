<?php

namespace App\Mail;

use App\Models\Business;
use App\Support\SupportContact;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a un negocio con prueba vencida hace +60 dias y sin plan activo: la
 * cuenta se eliminara pronto. Enviado por businesses:warn-inactive-trial. Los
 * headers X-Nexolu-Business-Id / X-Nexolu-Email-Type hacen que LogSentEmail
 * clasifique este envio automaticamente en el historial de correos del panel
 * de SuperAdmin.
 */
class InactiveTrialWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Business $business,
        public string $ownerName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu cuenta de Nexolú POS será eliminada pronto — '.$this->business->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inactive-trial-warning',
            with: [
                'business_name' => $this->business->name,
                'owner_name' => $this->ownerName,
                'trial_expired_at' => $this->business->trial_ends_at?->setTimezone('America/Bogota')->format('d/m/Y'),
                'app_url' => config('app.url'),
                'whatsapp_url' => SupportContact::whatsappUrl('Hola! quiero reactivar mi cuenta en Nexolu'),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->business->id,
                'X-Nexolu-Email-Type' => 'inactive_trial_warning',
            ],
        );
    }
}
