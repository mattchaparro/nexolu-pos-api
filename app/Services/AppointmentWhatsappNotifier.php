<?php

namespace App\Services;

use App\Models\Appointment;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Support\ChannelPhone;
use Illuminate\Support\Carbon;

/**
 * Manda por WhatsApp la confirmacion al agendar y el recordatorio 2h antes
 * de una cita - funcionalidad nueva, sin equivalente en legacy (que solo
 * mandaba un recordatorio por correo el dia anterior, ver
 * AppointmentsSendReminders). Le escribe al TELEFONO DEL CLIENTE
 * (client_phone), no a un usuario con canal vinculado - por eso no usa
 * AiChannelIdentity/WhatsAppRecipients, que resuelven destinatarios del
 * lado del negocio (staff).
 *
 * Ambas plantillas se dejan sin nombre real configurado (ver
 * config/services.php, WHATSAPP_TEMPLATE_CITA_*): sin plantilla aprobada
 * en Meta, sendTemplate() no tiene que donde apuntar - mismo patron
 * tolerante que services.whatsapp.flows.gasto.id.
 */
class AppointmentWhatsappNotifier
{
    public function __construct(private MessagingChannel $client) {}

    public function sendConfirmation(Appointment $appointment): bool
    {
        return $this->send($appointment, 'cita_confirmacion', fn () => $this->confirmationComponents($appointment));
    }

    public function sendReminder(Appointment $appointment): bool
    {
        return $this->send($appointment, 'cita_recordatorio', fn () => $this->reminderComponents($appointment));
    }

    public function templateConfigured(string $key): bool
    {
        return filled(config("services.whatsapp.templates.{$key}.name"));
    }

    /** @param  \Closure(): list<array<string, mixed>>  $components */
    private function send(Appointment $appointment, string $templateKey, \Closure $components): bool
    {
        if (! $this->templateConfigured($templateKey) || empty($appointment->client_phone)) {
            return false;
        }

        $phone = ChannelPhone::normalize($appointment->client_phone);
        if ($phone === null) {
            return false;
        }

        $template = config("services.whatsapp.templates.{$templateKey}");

        return $this->client->sendTemplate($phone, $template['name'], $template['lang'] ?? 'es_CO', $components(), $appointment->business_id, $templateKey);
    }

    /** @return list<array<string, mixed>> */
    private function confirmationComponents(Appointment $appointment): array
    {
        $starts = $this->localStart($appointment);
        $businessName = $appointment->business?->name ?? config('app.name');

        return [[
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => mb_substr($appointment->client_name, 0, 60)],
                ['type' => 'text', 'text' => mb_substr((string) $businessName, 0, 60)],
                ['type' => 'text', 'text' => $starts->translatedFormat('l j \d\e F')],
                ['type' => 'text', 'text' => $starts->format('g:i a')],
            ],
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function reminderComponents(Appointment $appointment): array
    {
        $starts = $this->localStart($appointment);
        $businessName = $appointment->business?->name ?? config('app.name');

        return [[
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => mb_substr($appointment->client_name, 0, 60)],
                ['type' => 'text', 'text' => mb_substr((string) $businessName, 0, 60)],
                ['type' => 'text', 'text' => $starts->format('g:i a')],
            ],
        ]];
    }

    private function localStart(Appointment $appointment): Carbon
    {
        return $appointment->starts_at->copy()->timezone('America/Bogota');
    }
}
