<?php

namespace App\Console\Commands;

use App\Mail\InactiveTrialWarningMail;
use App\Models\Business;
use App\Models\EmailLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa a negocios con prueba vencida hace +60 dias y sin plan activo que su
 * cuenta sera eliminada. Con cooldown de reenvio: no se manda dos veces el
 * mismo aviso dentro de 30 dias, aunque el cron corra todos los dias.
 */
#[Signature('businesses:warn-inactive-trial')]
#[Description('Avisa a negocios con prueba vencida +60 dias y sin plan activo que su cuenta sera eliminada')]
class BusinessesWarnInactiveTrial extends Command
{
    private const INACTIVE_DAYS = 60;

    private const RESEND_COOLDOWN_DAYS = 30;

    private const EMAIL_TYPE = 'inactive_trial_warning';

    public function handle(): int
    {
        $cutoff = now()->subDays(self::INACTIVE_DAYS);

        $businesses = Business::where('active', true)
            ->whereNull('paid_until')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', $cutoff)
            ->get();

        $alreadySent = EmailLog::where('type', self::EMAIL_TYPE)
            ->where('created_at', '>=', now()->subDays(self::RESEND_COOLDOWN_DAYS))
            ->whereIn('business_id', $businesses->pluck('id'))
            ->pluck('business_id')
            ->flip();

        $sent = 0;

        foreach ($businesses as $business) {
            if (isset($alreadySent[$business->id])) {
                continue;
            }

            $admin = $business->users()
                ->whereHas('roles', fn (Builder $q) => $q->where('name', 'admin'))
                ->first();

            if (! $admin) {
                continue;
            }

            $ownerName = $business->owner_name ?: explode('@', $admin->email)[0];

            try {
                Mail::to($admin->email)->send(new InactiveTrialWarningMail($business, $ownerName));
                $sent++;
            } catch (\Throwable) {
                continue;
            }
        }

        $this->info("Avisos de inactividad enviados: {$sent}");

        return self::SUCCESS;
    }
}
