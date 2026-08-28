<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Expense;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BusinessMigrationPatchControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.legacy.admin_key' => 'test-legacy-admin-key']);
        // legacy:normalize-payment-methods exige --business fuera de
        // local/staging para producir un cambio real en este test -
        // el controller siempre lo manda, asi que esto no afecta la
        // llamada, solo lo dejamos explicito para que el test no dependa
        // del ambiente en que corre la suite.
    }

    private function invoke(?string $key, int $businessId)
    {
        $request = $key ? $this->withHeader('Authorization', "Bearer {$key}") : $this;

        return $request->postJson("/api/admin/businesses/{$businessId}/run-migration-patches");
    }

    public function test_rejects_request_without_a_valid_key(): void
    {
        $business = Business::factory()->create();

        $this->invoke(null, $business->id)->assertStatus(401);
        $this->invoke('wrong-key', $business->id)->assertStatus(401);
    }

    public function test_returns_404_when_business_does_not_exist(): void
    {
        $this->invoke('test-legacy-admin-key', 999999)->assertStatus(404);
    }

    public function test_runs_the_three_commands_scoped_to_the_business(): void
    {
        $business = Business::factory()->create();
        Expense::factory()->create(['business_id' => $business->id, 'payment_method' => 'Efectivo']);

        $response = $this->invoke('test-legacy-admin-key', $business->id);

        $response->assertOk()->assertJsonStructure(['results' => [['command', 'ok', 'output']]]);
        $commands = collect($response->json('results'))->pluck('command')->all();
        $this->assertSame([
            'legacy:normalize-payment-methods',
            'payment-methods:migrate-catalog',
            'clients:backfill-links',
        ], $commands);
        $this->assertTrue(collect($response->json('results'))->every(fn ($r) => $r['ok'] === true));
    }
}
