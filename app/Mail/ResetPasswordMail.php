<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Enlace para restablecer la contraseña, disparado por
 * User::sendPasswordResetNotification() (override del comportamiento por
 * defecto de Laravel, que usaria una Notification en vez de un Mailable -
 * mismo patron ya establecido en el resto de esta API). A diferencia del
 * legacy (monolito con Blade/Inertia, el link apunta a su propia ruta web),
 * $reset_url apunta al frontend separado (nexolu-pos-front), unico lugar
 * donde existe el formulario para escribir la contraseña nueva - ver
 * config('app.frontend_url').
 *
 * Los headers X-Nexolu-Business-Id / X-Nexolu-Email-Type hacen que
 * LogSentEmail clasifique este envio automaticamente en el historial de
 * correos del panel de SuperAdmin.
 */
class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Restablece tu contraseña en Nexolú',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reset-password',
            with: [
                'user_name' => $this->user->name,
                'reset_url' => rtrim(config('app.frontend_url'), '/').'/restablecer-contrasena?'.http_build_query([
                    'token' => $this->token,
                    'email' => $this->user->email,
                ]),
                'expire_minutes' => (int) config('auth.passwords.users.expire', 60),
            ],
        );
    }

    public function headers(): Headers
    {
        // business_id puede ser null (un superadmin tambien puede pedir
        // reset de su propia contraseña) - omitir el header en vez de
        // mandar un string vacio, que LogSentEmail interpretaria como
        // business_id=0 (no existe ese negocio).
        return new Headers(
            text: array_filter([
                'X-Nexolu-Business-Id' => $this->user->business_id ? (string) $this->user->business_id : null,
                'X-Nexolu-Email-Type' => 'password_reset',
            ]),
        );
    }
}
