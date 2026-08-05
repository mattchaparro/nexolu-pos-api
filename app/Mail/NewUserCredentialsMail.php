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
 * Credenciales de acceso para un usuario recien creado por el admin de un
 * negocio. La contrasena viaja en claro por unica vez - nunca se persiste asi
 * (a diferencia del legacy, que llenaba users.plain_password). Es la unica
 * forma de que el usuario reciba su clave inicial sin obligar al admin a
 * inventarse una y compartirla por otro canal.
 *
 * Los headers X-Nexolu-Business-Id / X-Nexolu-Email-Type hacen que
 * LogSentEmail clasifique este envio automaticamente en el historial de
 * correos del panel de SuperAdmin.
 */
class NewUserCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Business $business,
        public string $plainPassword,
        public string $roleLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu acceso al POS de '.$this->business->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-user-credentials',
            with: [
                'user_name' => $this->user->name,
                'user_email' => $this->user->email,
                'plain_password' => $this->plainPassword,
                'role_label' => $this->roleLabel,
                'business_name' => $this->business->name,
                'app_url' => config('app.url'),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Nexolu-Business-Id' => (string) $this->business->id,
                'X-Nexolu-Email-Type' => 'new_user_credentials',
            ],
        );
    }
}
