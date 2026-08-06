<?php

use App\Support\CronJobCatalog;
use App\Support\CronJobLogger;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Jobs programados de esta API. Mismos horarios que legacy: suscripciones
 * pagas primero, trials despues, ambos en la mañana en Colombia para que el
 * dueño lo vea temprano, no a medianoche.
 *
 * Cada uno pasa por $logCronRun() (rastro de corridas, consultable en
 * SuperAdmin > Jobs programados, ver App\Support\CronJobLogger) y se puede
 * desactivar sin redeploy desde ahi (App\Support\CronJobCatalog, fuente
 * unica de verdad de la lista de jobs - agregar uno nuevo es tocar ese
 * array, no repetir el `when()` de abajo a mano).
 */
$logCronRun = fn (string $command, string $jobKey) => CronJobLogger::attachTo(Schedule::command($command), $jobKey)
    ->when(fn () => CronJobCatalog::isEnabled($jobKey));

$logCronRun('subscriptions:notify-expiring', 'subscription_expiring')->dailyAt('08:30');
$logCronRun('trials:notify-expiring', 'trial_expiring')->dailyAt('09:00');
$logCronRun('audit:prune', 'audit_prune')->dailyAt('03:15');
$logCronRun('appointments:send-reminders', 'appointment_reminders')->dailyAt('09:00');
$logCronRun('inventory:send-low-stock-alerts', 'low_stock_alerts')->dailyAt('08:00');
// Cada 5 min y no una hora fija: un recordatorio "hoy a las 5:00pm" tiene
// que dispararse cerca de esa hora, sea cual sea.
$logCronRun('reminders:send-whatsapp-notifications', 'reminder_whatsapp')->everyFiveMinutes();
$logCronRun('expenses:register-scheduled', 'scheduled_expenses')->dailyAt('00:05');
$logCronRun('notifications:send-daily-whatsapp-summary', 'daily_whatsapp_summary')->dailyAt('20:00');
$logCronRun('businesses:send-trial-winback', 'trial_winback')->weeklyOn(1, '10:00');
$logCronRun('businesses:warn-inactive-trial', 'inactive_trial_warning')->dailyAt('10:00');
// 06:00: temprano, para que TODO gasto del dia (IA y WhatsApp) se pueda
// valorar con la tasa de ese mismo dia. La TRM ya esta publicada a esa hora.
$logCronRun('exchange-rate:fetch', 'exchange_rate_fetch')->dailyAt('06:00');
