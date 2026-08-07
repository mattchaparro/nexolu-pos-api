<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\CronJobLog;
use App\Support\AuditLogger;
use App\Support\CronJobCatalog;
use App\Support\CronJobLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * Panel de observabilidad de los jobs programados de esta API: ultima
 * corrida, historial reciente, prender/apagar sin redeploy, y disparar uno
 * puntual fuera de su horario ("run now", para soporte/debug).
 */
class CronJobController extends Controller
{
    private const RECENT_LOGS_PER_JOB = 10;

    public function index(): JsonResponse
    {
        $keys = CronJobCatalog::keys();

        $logsByJob = CronJobLog::whereIn('job_key', $keys)
            ->orderByDesc('ran_at')
            ->get()
            ->groupBy('job_key');

        $jobs = collect(CronJobCatalog::all())->map(function (array $job) use ($logsByJob) {
            $logs = $logsByJob->get($job['key'], collect());
            $last = $logs->first();

            return [
                ...$job,
                'enabled' => CronJobCatalog::isEnabled($job['key']),
                'last_run' => $last ? $this->formatLog($last) : null,
                'recent_logs' => $logs->take(self::RECENT_LOGS_PER_JOB)->map($this->formatLog(...))->values(),
            ];
        })->values();

        return response()->json(['data' => $jobs]);
    }

    public function toggle(string $key): JsonResponse
    {
        $job = CronJobCatalog::find($key);
        abort_unless($job, 404, 'Job desconocido.');

        $enabled = ! CronJobCatalog::isEnabled($key);
        CronJobCatalog::setEnabled($key, $enabled);

        AuditLogger::log('superadmin.cron_job.toggled', ['job_key' => $key, 'enabled' => $enabled]);

        return response()->json(['key' => $key, 'enabled' => $enabled]);
    }

    public function runNow(string $key): JsonResponse
    {
        $job = CronJobCatalog::find($key);
        abort_unless($job, 404, 'Job desconocido.');

        try {
            $exitCode = Artisan::call($job['command']);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            CronJobLogger::record($key, CronJobLog::STATUS_ERROR, $e->getMessage(), CronJobLog::TRIGGERED_BY_MANUAL);

            AuditLogger::log('superadmin.cron_job.run_now', ['job_key' => $key, 'status' => 'error']);

            return response()->json(['error' => $e->getMessage()], 500);
        }

        $status = $exitCode === 0 ? CronJobLog::STATUS_SUCCESS : CronJobLog::STATUS_ERROR;
        CronJobLogger::record($key, $status, $output, CronJobLog::TRIGGERED_BY_MANUAL);

        AuditLogger::log('superadmin.cron_job.run_now', ['job_key' => $key, 'status' => $status]);

        return response()->json(['key' => $key, 'status' => $status, 'output' => trim($output) ?: null]);
    }

    /** @return array<string, mixed> */
    private function formatLog(CronJobLog $log): array
    {
        return [
            'ran_at' => $log->ran_at->toIso8601String(),
            'status' => $log->status,
            'output' => $log->output,
            'triggered_by' => $log->triggered_by,
        ];
    }
}
