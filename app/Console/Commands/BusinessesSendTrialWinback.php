<?php

namespace App\Console\Commands;

use App\Mail\TrialWinbackMail;
use App\Models\Business;
use App\Models\EmailLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;

/**
 * Envia correo de reactivacion a negocios que iniciaron prueba (hace entre 3
 * y 60 dias) pero no volvieron a activar un plan. Un winback por negocio,
 * sin ventana de tiempo: si ya se envio una vez, nunca se repite.
 */
#[Signature('businesses:send-trial-winback')]
#[Description('Envia correo de reactivacion a negocios que iniciaron prueba pero no regresaron')]
class BusinessesSendTrialWinback extends Command
{
    private const TRIAL_EXPIRED_DAYS_MIN = 3;

    private const TRIAL_EXPIRED_DAYS_MAX = 60;

    private const TRIAL_EXTENSION_DAYS = 7;

    private const EMAIL_TYPE = 'trial_winback';

    public function handle(): int
    {
        $expiredMin = now()->subDays(self::TRIAL_EXPIRED_DAYS_MIN);
        $expiredMax = now()->subDays(self::TRIAL_EXPIRED_DAYS_MAX);

        $businesses = Business::where('active', true)
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$expiredMax, $expiredMin])
            ->where(function (Builder $q) {
                $q->whereNull('paid_until')->orWhere('paid_until', '<', now());
            })
            ->get();

        $alreadySent = EmailLog::where('type', self::EMAIL_TYPE)
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

            $business->extendTrial(self::TRIAL_EXTENSION_DAYS);
            $ownerName = $business->owner_name ?: explode('@', $admin->email)[0];

            try {
                Mail::to($admin->email)->send(new TrialWinbackMail($business->fresh(), $ownerName, self::TRIAL_EXTENSION_DAYS));
                $sent++;
            } catch (\Throwable) {
                continue;
            }
        }

        $this->info("Winback enviados: {$sent}");

        return self::SUCCESS;
    }
}
