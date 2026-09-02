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
// Cada 15 min y no una hora fija: un carrito se abandona a cualquier hora y
// el recordatorio pierde valor rapido -- a la hora ya se le paso la
// intencion de compra, no al dia siguiente.
$logCronRun('carts:send-abandoned-reminders', 'abandoned_cart_reminders')->everyFifteenMinutes();
$logCronRun('appointments:send-reminders', 'appointment_reminders')->dailyAt('09:00');
// Cada 5 min, mismo motivo que reminder_whatsapp: una cita a cualquier hora
// necesita que el aviso se dispare cerca de "2h antes", no en una hora fija.
$logCronRun('appointments:send-two-hour-reminders', 'appointment_two_hour_reminders')->everyFiveMinutes();
// Cada 5 min y no una hora fija: cada negocio elige su propia hora desde
// Ajustes (Business::notificationHour('inventario_bajo'), default 08:00) -
// mismo motivo que reminder_whatsapp/appointment_two_hour_reminders, el
// comando ya se auto-limita a evaluar cada negocio una sola vez por dia.
$logCronRun('inventory:send-low-stock-alerts', 'low_stock_alerts')->everyFiveMinutes();
// Cada 5 min y no una hora fija: un recordatorio "hoy a las 5:00pm" tiene
// que dispararse cerca de esa hora, sea cual sea.
$logCronRun('reminders:send-whatsapp-notifications', 'reminder_whatsapp')->everyFiveMinutes();
$logCronRun('expenses:register-scheduled', 'scheduled_expenses')->dailyAt('00:05');
// Cada 10 min: la reserva de un pedido sin confirmar vence a las 24h, y
// mantenerla mas tiempo del debido significa no vender algo que si hay.
$logCronRun('orders:expire-stale', 'online_orders_expire')->everyTenMinutes();
// Cobros de datafono que nadie resolvio: sin esto quedan "esperando al
// cliente" para siempre y ensucian la caja del dia siguiente.
$logCronRun('terminals:expire-stale', 'terminal_charges_expire')->everyTenMinutes();
// Cada 5 min, mismo motivo que low_stock_alerts arriba: la hora la elige
// cada negocio (default 20:00, ver NotificationTypes), no todos a la vez.
$logCronRun('notifications:send-daily-whatsapp-summary', 'daily_whatsapp_summary')->everyFiveMinutes();
$logCronRun('businesses:send-trial-winback', 'trial_winback')->weeklyOn(1, '10:00');
$logCronRun('businesses:warn-inactive-trial', 'inactive_trial_warning')->dailyAt('10:00');
// 06:00: temprano, para que TODO gasto del dia (IA y WhatsApp) se pueda
// valorar con la tasa de ese mismo dia. La TRM ya esta publicada a esa hora.
$logCronRun('exchange-rate:fetch', 'exchange_rate_fetch')->dailyAt('06:00');
