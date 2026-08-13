<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Reminder;
use App\Services\AppointmentWhatsappNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Avisa por WhatsApp al CLIENTE que su cita es en 2 horas - funcionalidad
 * nueva, sin equivalente en legacy (que solo mandaba un recordatorio por
 * correo el dia anterior, ver AppointmentsSendReminders). Sin columna
 * propia para trackear el envio (appointments es tabla compartida con el
 * monolito, no se le puede agregar una - ver CLAUDE.md): AppointmentService
 * crea una fila Reminder ligada a la cita via remindable (mismo patron ya
 * usado para Purchase/Supplier/FixedExpenseTemplate/Expense) con
 * notify_whatsapp=false a proposito, para que reminders:send-whatsapp-
 * notifications (que le avisa al STAFF via su AiChannelIdentity) la ignore
 * por completo - este comando es el unico que la procesa, y le escribe al
 * telefono del cliente, no al creador. Pasar a 'done' es el mismo
 * mecanismo de "ya se envio" que el resto de reminders.
 */
#[Signature('appointments:send-two-hour-reminders')]
#[Description('Avisa por WhatsApp al cliente que su cita es en 2 horas')]
class AppointmentsSendTwoHourReminders extends Command
{
    public function handle(AppointmentWhatsappNotifier $notifier): int
    {
        if (! $notifier->templateConfigured('cita_recordatorio')) {
            $this->warn('Plantilla cita_recordatorio no configurada todavia. Nada que enviar.');

            return self::SUCCESS;
        }

        $now = Carbon::now('America/Bogota');
        $today = $now->toDateString();

        $pending = Reminder::query()
            ->where('remindable_type', Appointment::class)
            ->where('status', Reminder::STATUS_PENDING)
            ->whereDate('due_date', '<=', $today)
            ->with('remindable')
            ->get()
            ->filter(fn (Reminder $r) => $r->due_date->toDateString() < $today || $now->format('H:i') >= substr((string) $r->notify_time, 0, 5));

        $sent = 0;

        foreach ($pending as $reminder) {
            $appointment = $reminder->remindable;

            if (! $appointment instanceof Appointment || $appointment->status === 'cancelled') {
                $reminder->delete();

                continue;
            }

            if (empty($appointment->client_phone)) {
                $reminder->update(['status' => Reminder::STATUS_DONE, 'completed_at' => now()]);

                continue;
            }

            if ($notifier->sendReminder($appointment)) {
                $reminder->update(['status' => Reminder::STATUS_DONE, 'completed_at' => now()]);
                $sent++;
            }
            // Si falla (WhatsApp Cloud API caido, numero rechazado), queda
            // pendiente y se reintenta en la proxima corrida.
        }

        $this->info("Recordatorios de cita (2h) enviados: {$sent}");

        return self::SUCCESS;
    }
}
