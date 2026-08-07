<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Sale;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiInsightTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ia_core.api_key' => 'test-ia-core-key',
            'services.ia_core.base_url' => 'http://ia-core.test',
        ]);
        PermissionCatalog::sync();
    }

    public function test_rejects_a_user_without_the_ai_chat_permission(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/insights')
            ->assertStatus(422);
    }

    public function test_rejects_the_list_when_the_superadmin_blocked_ai_chat_for_the_business(): void
    {
        $business = Business::factory()->create(['ai_chat_blocked' => true]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/insights')
            ->assertStatus(403);
    }

    public function test_admin_lists_only_insights_worth_showing(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['text' => 'Hoy vas muy bien.'], 200)]);

        $business = Business::factory()->create(['feature_flags' => []]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');
        Sale::factory()->create(['business_id' => $business->id, 'total' => 50000, 'closed_at' => now()]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/insights');

        $response->assertOk();
        $types = collect($response->json('data'))->pluck('type');

        // resumen_inteligente y panorama_diario no requieren feature y hay
        // ventas de referencia hoy - deberian estar. Los que dependen de un
        // feature apagado (gastos, ingredientes, etc.) no.
        $this->assertContains('resumen_inteligente', $types);
        $this->assertContains('panorama_diario', $types);
        $this->assertNotContains('gastos_resumen', $types);
    }

    public function test_refresh_forces_regeneration_of_a_single_insight_type(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['text' => 'Texto fresco.'], 200)]);

        $business = Business::factory()->create(['feature_flags' => []]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');
        Sale::factory()->create(['business_id' => $business->id, 'total' => 50000, 'closed_at' => now()]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/insights/panorama_diario/refresh');

        $response->assertOk()->assertJsonPath('data.type', 'panorama_diario')
            ->assertJsonPath('data.text', 'Texto fresco.');
    }

    public function test_refresh_returns_404_for_an_unknown_insight_type(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/insights/no_existe/refresh')
            ->assertNotFound();
    }
}
