<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\BusinessPaymentSource;
use App\Models\SubscriptionCheckoutOrder;
use App\Services\SubscriptionNotifier;
use App\Services\SubscriptionService;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renovacion de la suscripcion el dia que vence y los dias siguientes.
 *
 * - Con medio de pago guardado y renovacion automatica: lo cobra el dia que
 *   vence y reintenta los dias de config('subscriptions.auto_renew_attempt_days').
 *   El resultado llega por webhook, que avisa por WhatsApp si se renovo o no.
 * - Sin medio guardado (o con la renovacion apagada): avisa por WhatsApp el
 *   dia que vence, el ultimo dia de gracia y el dia que se bloquea.
 *
 * Antes no habia nada de esto: un negocio vencia y seguia vendiendo sin que
 * nadie le cobrara (Las Banquitas, nov-2026). Cada negocio se evalua una sola
 * vez por dia (subscription_renewal_checked_on).
 */
#[Signature('subscriptions:renew {--business_id= : Solo para un negocio}')]
#[Description('Cobra la renovacion con el medio de pago guardado y avisa por WhatsApp a los negocios que vencen')]
class SubscriptionsRenew extends Command
{
    /** Hasta cuantos dias despues de vencer se sigue cobrando o avisando. */
    private const MAX_DAYS_LATE = 7;

    public function handle(SubscriptionService $subscriptions, SubscriptionNotifier $notifier): int
    {
        $today = now()->startOfDay();
        $attemptDays = config('subscriptions.auto_renew_attempt_days', []);

        $query = Business::query()
            ->where('active', true)
            ->where(function ($q) use ($today) {
                $window = [$today->copy()->subDays(self::MAX_DAYS_LATE), $today->copy()->endOfDay()];
                $q->whereBetween('paid_until', $window)
                    ->orWhere(fn ($trial) => $trial->whereNull('paid_until')->whereBetween('trial_ends_at', $window));
            });

        if ($businessId = $this->option('business_id')) {
            $query->where('id', $businessId);
        }

        $charged = 0;
        $reminded = 0;

        foreach ($query->get() as $business) {
            // Un pago hecho entre ayer y hoy ya lo saco de la ventana.
            $dueDay = $business->coverageEndsAt()?->copy()->startOfDay();
            if (! $dueDay || $dueDay->isAfter($today) || $business->subscription_renewal_checked_on?->isSameDay($today)) {
                continue;
            }

            $business->update(['subscription_renewal_checked_on' => $today->toDateString()]);
            $daysLate = (int) $dueDay->diffInDays($today);

            $source = $business->paid_until
                ? BusinessPaymentSource::where('business_id', $business->id)->where('status', 'active')->latest('id')->first()
                : null;

            if ($source && $business->subscription_auto_renew) {
                if (in_array($daysLate, $attemptDays, true) && $this->charge($business, $source, $subscriptions, $notifier)) {
                    $charged++;
                }

                continue;
            }

            if ($this->remind($business, $today, $notifier) > 0) {
                $reminded++;
            }
        }

        $this->info("Cobros automaticos enviados: {$charged}. Avisos por WhatsApp: {$reminded}.");

        return self::SUCCESS;
    }

    private function charge(
        Business $business,
        BusinessPaymentSource $source,
        SubscriptionService $subscriptions,
        SubscriptionNotifier $notifier,
    ): bool {
        // Un cobro que todavia espera respuesta del banco no se repite.
        $pending = SubscriptionCheckoutOrder::where('business_id', $business->id)
            ->where('origin', SubscriptionService::ORIGIN_AUTO_RENEW)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subDay())
            ->exists();
        if ($pending) {
            return false;
        }

        try {
            $subscriptions->chargeSavedSource($business, $source);

            return true;
        } catch (Throwable $e) {
            Log::warning('subscriptions.renew: el cobro automatico no salio', [
                'business_id' => $business->id,
                'error' => $e->getMessage(),
            ]);
            $notifier->chargeFailed($business, $subscriptions->renewalAmountCop($business));

            return false;
        }
    }

    /** Aviso del dia: vence hoy, ultimo dia de gracia, o quedo bloqueado. */
    private function remind(Business $business, CarbonInterface $today, SubscriptionNotifier $notifier): int
    {
        $graceEnd = $business->graceEndsAt()?->copy()->startOfDay();
        $dueDay = $business->coverageEndsAt()->copy()->startOfDay();

        return match (true) {
            $dueDay->equalTo($today) => $notifier->dueToday($business),
            $graceEnd?->equalTo($today) => $notifier->lastGraceDay($business),
            $graceEnd?->copy()->addDay()->equalTo($today) => $notifier->lockedOut($business),
            default => 0,
        };
    }
}
