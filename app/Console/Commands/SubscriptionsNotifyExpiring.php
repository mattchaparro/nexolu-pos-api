<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionExpiringMail;
use App\Models\Business;
use App\Models\BusinessPaymentSource;
use App\Models\User;
use App\Services\SubscriptionNotifier;
use App\Services\SubscriptionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa por correo y WhatsApp al dueño de un negocio cuando su suscripcion PAGADA esta
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
#[Description('Notifica por correo y WhatsApp a los negocios cuya suscripcion pagada vence pronto')]
class SubscriptionsNotifyExpiring extends Command
{
    public function handle(SubscriptionNotifier $notifier, SubscriptionService $subscriptions): int
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

            $mailed = false;
            if ($owner) {
                try {
                    Mail::to($owner->email)->send(
                        new SubscriptionExpiringMail($owner, $business, $business->paid_until, true)
                    );
                    $mailed = true;
                } catch (\Throwable) {
                    // Sigue con el WhatsApp: un correo caido no puede dejar
                    // al negocio sin aviso.
                }
            }

            // WhatsApp ademas del correo: casi nadie lee el correo. Si va a
            // haber cobro automatico, el mensaje dice cuanto, cuando y a que
            // medio de pago.
            $source = BusinessPaymentSource::where('business_id', $business->id)->where('status', 'active')->latest('id')->first();
            $whatsApped = $notifier->upcoming($business, $source, $subscriptions->renewalAmountCop($business)) > 0;

            if (! $mailed && ! $whatsApped) {
                continue;
            }

            $business->update(['subscription_expiry_notified_at' => now()]);
            $notified++;
        }

        $this->info("Notificados {$notified} de {$candidates->count()} negocios candidatos.");

        return self::SUCCESS;
    }
}
