<?php

namespace App\Services;

use App\Mail\BusinessDirectedMail;
use App\Models\Business;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Support\ChannelPhone;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Comunicacion puntual de un superadmin hacia un negocio: correo de
 * asunto/cuerpo libres, o WhatsApp via la plantilla generica ya aprobada en
 * Meta ('recordatorio' / general_reminder, un unico variable de texto libre
 * - mismo mecanismo que reminders:send-whatsapp-notifications). Sin
 * equivalente en el legacy (que solo mandaba 2 correos de marketing con
 * contenido fijo, ver docs/MIGRATION_BACKLOG.md) - disenado desde cero para
 * que el superadmin pueda escribir lo que quiera.
 *
 * Ambos canales quedan automaticamente en la bitacora de Comunicaciones: el
 * correo via LogSentEmail (headers X-Nexolu-*), el WhatsApp via
 * LoggingMessagingChannel (el binding real de MessagingChannel).
 */
class BusinessCommunicationService
{
    public function __construct(private MessagingChannel $client) {}

    /** @throws ValidationException|RuntimeException */
    public function send(Business $business, string $channel, ?string $subject, string $message): void
    {
        if ($channel === 'email') {
            $this->sendEmail($business, (string) $subject, $message);

            return;
        }

        $this->sendWhatsApp($business, $message);
    }

    private function sendEmail(Business $business, string $subject, string $message): void
    {
        $adminEmail = $business->users()->role('admin')->value('email');

        if (! $adminEmail) {
            throw ValidationException::withMessages([
                'channel' => 'Este negocio no tiene un usuario admin con correo registrado.',
            ]);
        }

        Mail::to($adminEmail)->send(new BusinessDirectedMail($business, $subject, $message));
    }

    private function sendWhatsApp(Business $business, string $message): void
    {
        $phone = $business->whatsapp_number ? ChannelPhone::normalize($business->whatsapp_number) : null;

        if (! $phone) {
            throw ValidationException::withMessages([
                'channel' => 'Este negocio no tiene un número de WhatsApp válido registrado.',
            ]);
        }

        $template = config('services.whatsapp.templates.recordatorio');

        if (empty($template['name'])) {
            throw new RuntimeException('La plantilla de WhatsApp genérica no está configurada todavía.');
        }

        $sent = $this->client->sendTemplate(
            $phone,
            $template['name'],
            $template['lang'] ?? 'es_CO',
            [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $message]]]],
            $business->id,
            'superadmin_directed',
        );

        if (! $sent) {
            throw new RuntimeException('No se pudo enviar el mensaje de WhatsApp.');
        }
    }
}
