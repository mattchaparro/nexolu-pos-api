<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Primeros jobs programados de esta API (el resto de los ~12 jobs del Kernel
// legacy quedan pendientes, se migran uno por uno). Mismos horarios que
// legacy: suscripciones pagas primero, trials despues, ambos en la mañana en
// Colombia para que el dueño lo vea temprano, no a medianoche.
Schedule::command('subscriptions:notify-expiring')->dailyAt('08:30');
Schedule::command('trials:notify-expiring')->dailyAt('09:00');
Schedule::command('audit:prune')->dailyAt('03:15');
Schedule::command('appointments:send-reminders')->dailyAt('09:00');
Schedule::command('inventory:send-low-stock-alerts')->dailyAt('08:00');
Schedule::command('reminders:send-whatsapp-notifications')->everyFiveMinutes();
Schedule::command('expenses:register-scheduled')->dailyAt('00:05');
Schedule::command('notifications:send-daily-whatsapp-summary')->dailyAt('20:00');
Schedule::command('businesses:send-trial-winback')->weeklyOn(1, '10:00');
Schedule::command('businesses:warn-inactive-trial')->dailyAt('10:00');
