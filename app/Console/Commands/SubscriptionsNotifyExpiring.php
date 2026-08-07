<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionExpiringMail;
use App\Models\Business;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa por correo al dueño de un negocio cuando su suscripcion PAGADA esta
 * por vencer, dentro de la ventana de dias configurada. El aviso de trials
 * es un comando aparte (trials:notify-expiring) porque legacy lo trata con
 * una cadencia distinta (dos ventanas: 3 dias y 1 dia).
 *
 * subscription_expiry_notified_at evita reenviar el aviso todos los dias
 * mientras dure la ventana: solo se vuelve a notificar si el ultimo aviso
 * quedo ANTES de que abriera la ventana del vencimiento actual. Cuando el
 * negocio renueva (Business::activate() empuja paid_until mas adelante), la
 * ventana se recalcula sola y el aviso vuelve a dispararse en el proximo
 * ciclo - no hace falta limpiar el campo a mano.
 */
#[Signature('subscriptions:notify-expiring {--days=3 : Dias de anticipacion antes del vencimiento}')]
#[Description('Notifica por correo a los negocios cuya suscripcion pagada vence pronto')]
class SubscriptionsNotifyExpiring extends Command
{
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $windowEnd = now()->addDays($days)->endOfDay();

        $candidates = Business::query()
            ->where('active', true)
            ->whereNotNull('paid_until')
            ->whereBetween('paid_until', [now(), $windowEnd])
            ->get();

        $notified = 0;

        foreach ($candidates as $business) {
            $windowStart = $business->paid_until->copy()->subDays($days);
            if ($business->subscription_expiry_notified_at?->gte($windowStart)) {
                continue;
            }

            $owner = User::where('business_id', $business->id)->where('is_business_owner', true)->first();
            if (! $owner) {
                continue;
            }

            try {
                Mail::to($owner->email)->send(
                    new SubscriptionExpiringMail($owner, $business, $business->paid_until, true)
                );
            } catch (\Throwable) {
                continue;
            }

            $business->update(['subscription_expiry_notified_at' => now()]);
            $notified++;
        }

        $this->info("Notificados {$notified} de {$candidates->count()} negocios candidatos.");

        return self::SUCCESS;
    }
}
