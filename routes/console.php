<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Primer job programado de esta API (el resto de los ~12 jobs del Kernel
// legacy quedan pendientes, se migran uno por uno). Horario de la mañana en
// Colombia para que el dueño lo vea temprano, no a medianoche.
Schedule::command('subscriptions:notify-expiring')->dailyAt('08:00');
