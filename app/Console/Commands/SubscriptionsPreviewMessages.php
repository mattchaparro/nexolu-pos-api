<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\BusinessPaymentSource;
use App\Services\SubscriptionNotifier;
use App\Services\SubscriptionService;
use App\Support\ChannelPhone;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Manda a un solo numero cada mensaje de WhatsApp de la suscripcion, con los
 * datos de un negocio real, para ver como llegan. No guarda nada: las fechas
 * de cada caso se arman sobre una copia del negocio en memoria.
 */
#[Signature('subscriptions:preview-messages {phone : Celular que recibe los mensajes} {--business_id= : Negocio del que salen nombre, monto y medio de pago} {--pause=2 : Segundos entre mensajes, para que lleguen en orden}')]
#[Description('Envia a un numero todos los mensajes de WhatsApp de la suscripcion, para revisarlos')]
class SubscriptionsPreviewMessages extends Command
{
    public function handle(SubscriptionNotifier $notifier, SubscriptionService $subscriptions): int
    {
        $phone = ChannelPhone::normalize((string) $this->argument('phone'));
        $business = Business::find($this->option('business_id'));
        if (! $phone || ! $business) {
            $this->error('Hace falta un celular valido y --business_id de un negocio que exista.');

            return self::FAILURE;
        }

        // Las fechas con la regla normal, sin la transicion de nov-2026.
        config(['subscriptions.lockout_not_before' => null]);

        $to = $notifier->to($phone);
        $amount = $subscriptions->renewalAmountCop($business);
        $source = BusinessPaymentSource::where('business_id', $business->id)->latest('id')->first()
            ?? new BusinessPaymentSource(['label' => 'Visa •••• 4242']);

        $at = fn ($paidUntil) => tap(clone $business, function (Business $copy) use ($paidUntil) {
            $copy->paid_until = $paidUntil;
            $copy->trial_ends_at = null;
            $copy->subscription_auto_renew = true;
        });

        $cases = [
            '3 días antes, con medio guardado' => fn () => $to->upcoming($at(now()->addDays(3)), $source, $amount),
            '3 días antes, sin medio guardado' => fn () => $to->upcoming($at(now()->addDays(3)), null, $amount),
            'Cobro exitoso' => fn () => $to->renewed($at(now()->addDays(30)), $amount),
            'Cobro fallido, con reintento' => fn () => $to->chargeFailed($at(now()), $amount),
            'Cobro fallido, ya bloqueado' => fn () => $to->chargeFailed($at(now()->subDays(8)), $amount),
            'Sin medio: vence hoy' => fn () => $to->dueToday($at(now())),
            'Sin medio: último día de gracia' => fn () => $to->lastGraceDay($at(now()->subDays(2))),
            'Sin medio: bloqueado' => fn () => $to->lockedOut($at(now()->subDays(3))),
            'Un empleado avisa del bloqueo' => fn () => $to->employeeFoundLockout($at(now()->subDays(3)), 'Camila'),
        ];

        $i = 0;
        foreach ($cases as $label => $send) {
            $i++;
            $sent = $send();
            $this->line(sprintf('%d. %s: %s', $i, $label, $sent ? 'enviado' : 'NO salio'));
            sleep((int) $this->option('pause'));
        }

        return self::SUCCESS;
    }
}
