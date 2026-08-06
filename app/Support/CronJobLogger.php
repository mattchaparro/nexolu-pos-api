<?php

namespace App\Support;

use App\Models\CronJobLog;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Rastro de corridas de jobs programados. A diferencia de legacy (que llama
 * CronJobLog::record() a mano dentro de cada comando, con un try/catch y un
 * mensaje armado a la medida en cada uno), acá se centraliza en
 * routes/console.php via attachTo(): captura la salida real de consola del
 * comando (`sendOutputTo`) y registra exito/fallo segun el exit code, sin
 * tocar ninguno de los comandos ya migrados. El "run now" manual del
 * superadmin (ver SuperAdmin\CronJobController::runNow()) sigue llamando
 * record() directo, porque Artisan::call() no dispara los hooks del
 * scheduler.
 */
class CronJobLogger
{
    const KEEP_PER_JOB = 50;

    /**
     * Engancha el logging automático a un evento del scheduler: captura la
     * salida de consola a un archivo temporal y registra el resultado
     * (exito/error segun el exit code) cuando termina.
     */
    public static function attachTo(Event $event, string $jobKey): Event
    {
        $outputPath = storage_path("framework/cache/cron-{$jobKey}.log");

        return $event->sendOutputTo($outputPath)->after(function () use ($event, $jobKey, $outputPath) {
            $output = File::exists($outputPath) ? File::get($outputPath) : '';
            $status = (int) $event->exitCode === 0 ? CronJobLog::STATUS_SUCCESS : CronJobLog::STATUS_ERROR;

            self::record($jobKey, $status, $output, CronJobLog::TRIGGERED_BY_SCHEDULER);
        });
    }

    /**
     * Nunca lanza: un fallo al loguear no debe tumbar el job real. Conserva
     * solo las ultimas KEEP_PER_JOB filas por job, igual que legacy, para no
     * inflar la tabla con el paso del tiempo.
     */
    public static function record(string $jobKey, string $status, string $output = '', string $triggeredBy = CronJobLog::TRIGGERED_BY_SCHEDULER): void
    {
        try {
            CronJobLog::create([
                'job_key' => $jobKey,
                'status' => $status,
                'output' => trim($output) !== '' ? mb_substr(trim($output), 0, 3000) : null,
                'triggered_by' => $triggeredBy,
            ]);

            // slice() en la Collection, no skip()/offset() en la query: MySQL
            // exige LIMIT junto con OFFSET, y un OFFSET solo es un error de
            // sintaxis silencioso (atrapado mas abajo) que nunca borraba nada
            // - bug real encontrado al portar, no solo trasladado.
            $staleIds = CronJobLog::where('job_key', $jobKey)
                ->orderByDesc('ran_at')
                ->pluck('id')
                ->slice(self::KEEP_PER_JOB);

            if ($staleIds->isNotEmpty()) {
                CronJobLog::whereIn('id', $staleIds)->delete();
            }
        } catch (Throwable) {
            // El log nunca debe romper el job real.
        }
    }
}
