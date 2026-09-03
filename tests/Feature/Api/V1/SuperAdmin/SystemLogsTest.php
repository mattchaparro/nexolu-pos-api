<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class SystemLogsTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Un archivo propio con nombre reconocible: el visor lee todo
        // storage/logs, y la suite escribe ahi tambien.
        $this->logFile = storage_path('logs/test-system-viewer.log');
        File::put($this->logFile, '');
    }

    protected function tearDown(): void
    {
        File::delete($this->logFile);
        parent::tearDown();
    }

    private function writeLog(string $contents): void
    {
        File::put($this->logFile, $contents);
        touch($this->logFile, time() + 5);
    }

    public function test_an_employee_cannot_read_the_logs(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/superadmin/system/logs')
            ->assertStatus(403);
    }

    public function test_it_parses_an_entry_with_its_context_and_stacktrace(): void
    {
        $this->writeLog(<<<'LOG'
[2026-09-03 10:15:00] production.ERROR: App\Exceptions\PaymentFailed: la pasarela respondio 500 {"reference":"nx-1"}
#0 /var/www/html/app/Services/SubscriptionService.php(120): charge()
#1 /var/www/html/vendor/laravel/framework/src/Foo.php(10): call()

LOG);

        $response = $this->actingAs($this->superadmin(), 'sanctum')
            ->getJson('/api/v1/superadmin/system/logs?search=la pasarela respondio')
            ->assertOk();

        $entry = collect($response->json('data'))->first(fn (array $row) => str_contains($row['message'], 'la pasarela respondio'));

        $this->assertNotNull($entry);
        $this->assertSame('ERROR', $entry['level']);
        $this->assertSame('App\Exceptions\PaymentFailed', $entry['exception_class']);
        // El contexto se separa del mensaje: pegado, un contexto largo lo tapa.
        $this->assertSame('nx-1', $entry['context']['reference']);
        $this->assertStringNotContainsString('{"reference"', $entry['message']);
        $this->assertCount(2, $entry['trace']);
    }

    /**
     * Las dos pestañas son niveles distintos, no un filtro cosmetico: mezclar
     * un INFO de rutina con un ERROR real es lo que hace que el visor deje de
     * mirarse.
     */
    public function test_the_errors_tab_excludes_routine_levels(): void
    {
        $this->writeLog(<<<'LOG'
[2026-09-03 10:00:00] production.INFO: pedido creado {"id":1}
[2026-09-03 10:01:00] production.ERROR: se cayo algo {"id":2}
LOG);

        $admin = $this->superadmin();

        $errors = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/system/logs?search=se cayo algo')
            ->assertOk();
        $this->assertCount(1, $errors->json('data'));

        $onlyInfoInErrorsTab = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/system/logs?search=pedido creado')
            ->assertOk();
        $this->assertCount(0, $onlyInfoInErrorsTab->json('data'));

        $logsTab = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/system/logs?tab=logs&search=pedido creado')
            ->assertOk();
        $this->assertCount(1, $logsTab->json('data'));
    }

    public function test_it_can_filter_by_date(): void
    {
        $this->writeLog(<<<'LOG'
[2026-09-01 10:00:00] production.ERROR: error viejo {"id":1}
[2026-09-03 10:00:00] production.ERROR: error de hoy {"id":2}
LOG);

        $response = $this->actingAs($this->superadmin(), 'sanctum')
            ->getJson('/api/v1/superadmin/system/logs?date=2026-09-01&search=error')
            ->assertOk();

        $messages = collect($response->json('data'))->pluck('message');
        $this->assertTrue($messages->contains('error viejo'));
        $this->assertFalse($messages->contains('error de hoy'));
    }

    /**
     * Sin saber si Sentry esta recibiendo en este ambiente, "no hay errores"
     * en esta pantalla se puede leer como "no hay errores en ningun lado".
     */
    public function test_it_reports_whether_sentry_is_configured(): void
    {
        config(['sentry.dsn' => null]);

        $this->actingAs($this->superadmin(), 'sanctum')
            ->getJson('/api/v1/superadmin/system/logs')
            ->assertOk()
            ->assertJsonPath('environment.sentry_configured', false);
    }
}
