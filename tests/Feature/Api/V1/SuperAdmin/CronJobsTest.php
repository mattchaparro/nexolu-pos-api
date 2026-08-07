<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\CronJobLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class CronJobsTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_index_lists_every_catalog_job_with_its_last_run_and_history(): void
    {
        $admin = $this->superadmin();
        CronJobLog::factory()->create([
            'job_key' => 'audit_prune', 'status' => 'success', 'output' => '45 registros eliminados.', 'ran_at' => now()->subDay(),
        ]);
        CronJobLog::factory()->create([
            'job_key' => 'audit_prune', 'status' => 'success', 'output' => '12 registros eliminados.', 'ran_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/cron-jobs');

        $response->assertOk();
        $jobs = collect($response->json('data'));
        $this->assertGreaterThanOrEqual(11, $jobs->count());

        $auditJob = $jobs->firstWhere('key', 'audit_prune');
        $this->assertSame('audit:prune', $auditJob['command']);
        $this->assertTrue($auditJob['enabled']);
        // El mas reciente por ran_at, no el ultimo insertado.
        $this->assertSame('12 registros eliminados.', $auditJob['last_run']['output']);
        $this->assertCount(2, $auditJob['recent_logs']);

        $neverRunJob = $jobs->firstWhere('key', 'exchange_rate_fetch');
        $this->assertNull($neverRunJob['last_run']);
        $this->assertSame([], $neverRunJob['recent_logs']);
    }

    public function test_toggle_flips_the_enabled_flag_and_persists_it(): void
    {
        $admin = $this->superadmin();

        $first = $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/superadmin/cron-jobs/audit_prune/toggle')
            ->assertOk()
            ->json();
        $this->assertFalse($first['enabled']);

        $listed = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/cron-jobs')->json('data');
        $this->assertFalse(collect($listed)->firstWhere('key', 'audit_prune')['enabled']);

        $second = $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/superadmin/cron-jobs/audit_prune/toggle')
            ->assertOk()
            ->json();
        $this->assertTrue($second['enabled']);
    }

    public function test_toggle_rejects_an_unknown_job_key(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/superadmin/cron-jobs/no-existe/toggle')
            ->assertStatus(404);
    }

    public function test_run_now_executes_the_command_and_logs_it_as_manual(): void
    {
        $admin = $this->superadmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/superadmin/cron-jobs/audit_prune/run-now');

        $response->assertOk()->assertJsonPath('key', 'audit_prune')->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('cron_job_logs', ['job_key' => 'audit_prune', 'triggered_by' => 'manual', 'status' => 'success']);
    }

    public function test_run_now_rejects_an_unknown_job_key(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/superadmin/cron-jobs/no-existe/run-now')
            ->assertStatus(404);
    }
}
