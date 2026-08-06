<?php

namespace App\Console\Commands;

use App\Models\AiChannelIdentity;
use App\Models\Reminder;
use App\Services\WhatsApp\WhatsAppCloudClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Avisa por WhatsApp los recordatorios pendientes que ya llegaron a su hora
 * (notify_time) y tienen notify_whatsapp activo. last_notified_on evita
 * reavisar el mismo dia; cuando un recurrente avanza de ciclo (due_date
 * cambia), vuelve a ser distinto de last_notified_on y se vuelve a avisar.
 *
 * Si el creador no tiene WhatsApp vinculado, igual se marca last_notified_on
 * para no reevaluar ese mismo recordatorio en cada corrida del dia. Si el
 * envio falla, NO se marca: se reintenta en la proxima corrida.
 */
#[Signature('reminders:send-whatsapp-notifications')]
#[Description('Avisa por WhatsApp los recordatorios pendientes que ya llegaron a su hora')]
class RemindersSendWhatsAppNotifications extends Command
{
    public function handle(WhatsAppCloudClient $client): int
    {
        $template = config('services.whatsapp.templates.recordatorio');

        if (empty($template['name'])) {
            $this->warn('Plantilla recordatorio no configurada todavia. Nada que enviar.');

            return self::SUCCESS;
        }

        $now = Carbon::now();
        $today = $now->toDateString();

        $pending = Reminder::query()
            ->where('status', Reminder::STATUS_PENDING)
            ->where('notify_whatsapp', true)
            ->whereNotNull('notify_time')
            ->whereDate('due_date', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('last_notified_on')->orWhereDate('last_notified_on', '<>', $today);
            })
            ->with('createdBy')
            ->get()
            ->filter(fn (Reminder $reminder) => $now->format('H:i') >= substr((string) $reminder->notify_time, 0, 5));

        $sent = 0;

        foreach ($pending as $reminder) {
            $user = $reminder->createdBy;

            $identity = $user ? AiChannelIdentity::query()
                ->where('user_id', $user->id)
                ->where('channel', AiChannelIdentity::CHANNEL_WHATSAPP)
                ->whereNotNull('verified_at')
                ->first() : null;

            if (! $identity) {
                $reminder->update(['last_notified_on' => $today]);

                continue;
            }

            if ($client->sendTemplate($identity->external_id, $template['name'], $template['lang'] ?? 'es', $this->components($reminder))) {
                $reminder->update(['last_notified_on' => $today]);
                $sent++;
            }
        }

        $this->info("Recordatorios avisados por WhatsApp: {$sent}");

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function components(Reminder $reminder): array
    {
        return [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($reminder->title, 0, 300)]]]];
    }
}
