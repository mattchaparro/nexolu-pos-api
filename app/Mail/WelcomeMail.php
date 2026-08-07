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
 * Bienvenida al dueño de un negocio recien autoregistrado. Los headers
 * X-Nexolu-Business-Id / X-Nexolu-Email-Type hacen que LogSentEmail
 * clasifique este envio automaticamente en el historial de correos del
 * panel de SuperAdmin.
 */
class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $owner,
        public Business $business,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Bienvenido a Nexolú, '.$this->business->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome',
            with: [
                'owner_name' => $this->owner->name,
                'business_name' => $this->business->name,
                'trial_ends_at' => $this->business->trial_ends_at,
                'app_url' => config('app.url'),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->business->id,
                'X-Nexolu-Email-Type' => 'welcome',
            ],
        );
    }
}
