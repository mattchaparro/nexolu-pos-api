<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionExpiringMail;
use App\Models\Business;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa por correo a los negocios en periodo de prueba que su trial vence en
 * 3 dias o en 1 dia (dos avisos separados, a diferencia de la suscripcion
 * pagada que solo tiene una ventana). El dedup no usa un campo en Business
 * (como subscriptions:notify-expiring) sino EmailLog: cada ventana tiene su
 * propio $emailType ('trial_expiring_3d' / 'trial_expiring_1d') y se salta
 * un negocio si ya se le envio ese tipo en los ultimos 2 dias.
 */
#[Signature('trials:notify-expiring')]
#[Description('Avisa por correo a los negocios en prueba que su trial vence en 3 dias o en 1 dia')]
class TrialsNotifyExpiring extends Command
{
    public function handle(): int
    {
        $sent = $this->notifyWindow(3, 'trial_expiring_3d')
            + $this->notifyWindow(1, 'trial_expiring_1d');

        $this->info("Avisos de trial por vencer enviados: {$sent}");

        return self::SUCCESS;
    }

    private function notifyWindow(int $days, string $emailType): int
    {
        $windowStart = now()->addDays($days - 1)->startOfDay();
        $windowEnd = now()->addDays($days)->endOfDay();

        $businesses = Business::query()
            ->where('active', true)
            ->whereNull('paid_until')
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$windowStart, $windowEnd])
            ->get();

        if ($businesses->isEmpty()) {
            return 0;
        }

        $alreadyNotified = EmailLog::query()
            ->where('type', $emailType)
            ->where('created_at', '>=', now()->subDays(2))
            ->whereIn('business_id', $businesses->pluck('id'))
            ->pluck('business_id')
            ->flip();

        $sent = 0;

        foreach ($businesses as $business) {
            if (isset($alreadyNotified[$business->id])) {
                continue;
            }

            $owner = User::where('business_id', $business->id)->where('is_business_owner', true)->first();
            if (! $owner) {
                continue;
            }

            try {
                Mail::to($owner->email)->send(
                    new SubscriptionExpiringMail($owner, $business, $business->trial_ends_at, false, $days, $emailType)
                );
            } catch (\Throwable) {
                continue;
            }

            $sent++;
        }

        return $sent;
    }
}
