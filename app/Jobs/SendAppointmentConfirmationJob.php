<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Services\AppointmentWhatsappNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Manda la confirmacion por WhatsApp de una cita recien agendada -
 * despachado desde AppointmentService::create() despues de que la
 * transaccion confirma (no adentro: QUEUE_CONNECTION=redis, after_commit
 * en false por defecto, asi que un worker rapido podria tomar el job antes
 * de que la fila exista de verdad). En cola para no bloquear el request de
 * agendar con la llamada de red a WhatsApp, igual que SendReceiptJob.
 */
class SendAppointmentConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $appointmentId) {}

    public function handle(AppointmentWhatsappNotifier $notifier): void
    {
        $appointment = Appointment::find($this->appointmentId);
        if (! $appointment) {
            return;
        }

        $notifier->sendConfirmation($appointment);
    }
}
