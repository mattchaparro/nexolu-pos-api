<?php

namespace App\Console\Commands;

use App\Mail\AppointmentReminderMail;
use App\Models\Appointment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Envia recordatorios por correo a clientes con cita al dia siguiente.
 *
 * reminder_sent se marca en true tanto si el correo se envio como si fallo
 * (o si no habia email a donde mandarlo), igual que en legacy: evita que una
 * falla de envio deje la cita reintentando el recordatorio indefinidamente
 * en cada corrida del cron.
 */
#[Signature('appointments:send-reminders')]
#[Description('Envia recordatorios por correo a clientes con cita al dia siguiente')]
class AppointmentsSendReminders extends Command
{
    public function handle(): int
    {
        $tomorrow = Carbon::tomorrow('America/Bogota');
        $start = $tomorrow->copy()->startOfDay();
        $end = $tomorrow->copy()->endOfDay();

        $appointments = Appointment::with(['service', 'staff', 'client', 'business'])
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('reminder_sent', false)
            ->whereBetween('starts_at', [$start, $end])
            ->get();

        $sent = 0;

        foreach ($appointments as $appointment) {
            $email = $appointment->client_email ?? $appointment->client?->email;

            if ($email) {
                try {
                    Mail::to($email)->send(new AppointmentReminderMail($appointment));
                    $sent++;
                } catch (\Throwable) {
                    // seguir con la siguiente cita; reminder_sent igual se marca abajo.
                }
            }

            $appointment->update(['reminder_sent' => true]);
        }

        $this->info("Recordatorios enviados: {$sent} de {$appointments->count()} citas candidatas.");

        return self::SUCCESS;
    }
}
