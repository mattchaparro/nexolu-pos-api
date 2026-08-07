<?php

namespace Tests\Feature\Support;

use App\Models\CronJobLog;
use App\Support\CronJobLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CronJobLoggerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_record_creates_a_log_row_truncated_to_3000_chars(): void
    {
        CronJobLogger::record('demo_job', CronJobLog::STATUS_SUCCESS, str_repeat('x', 4000), CronJobLog::TRIGGERED_BY_MANUAL);

        $log = CronJobLog::where('job_key', 'demo_job')->first();

        $this->assertNotNull($log);
        $this->assertSame(CronJobLog::STATUS_SUCCESS, $log->status);
        $this->assertSame(CronJobLog::TRIGGERED_BY_MANUAL, $log->triggered_by);
        $this->assertSame(3000, strlen($log->output));
    }

    public function test_record_stores_null_output_when_blank(): void
    {
        CronJobLogger::record('demo_job', CronJobLog::STATUS_SUCCESS, "   \n  ");

        $this->assertNull(CronJobLog::where('job_key', 'demo_job')->first()->output);
    }

    public function test_record_keeps_only_the_last_50_rows_per_job(): void
    {
        for ($i = 0; $i < 55; $i++) {
            CronJobLog::factory()->create(['job_key' => 'demo_job', 'ran_at' => now()->subMinutes(55 - $i)]);
        }

        CronJobLogger::record('demo_job', CronJobLog::STATUS_SUCCESS, 'ultima corrida');

        $this->assertSame(50, CronJobLog::where('job_key', 'demo_job')->count());
        $this->assertDatabaseHas('cron_job_logs', ['job_key' => 'demo_job', 'output' => 'ultima corrida']);
    }

    public function test_record_never_throws_on_an_invalid_status(): void
    {
        CronJobLogger::record('demo_job', 'no_es_un_status_valido', 'x');

        $this->assertDatabaseMissing('cron_job_logs', ['job_key' => 'demo_job']);
    }
}
