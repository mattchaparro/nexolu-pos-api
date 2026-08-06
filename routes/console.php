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
