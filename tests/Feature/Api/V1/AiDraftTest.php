<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cubre el proxy hacia POST /v1/drafts/{id}/confirm|discard del IA Core: el
 * POS arma el TenantContext del usuario autenticado y reenvia el codigo de
 * estado tal cual (200/404/409), sin envolverlo. No prueba el IA Core en si.
 */
class AiDraftTest extends TestCase
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
            ->postJson('/api/v1/ai/drafts/draft-1/confirm')
            ->assertStatus(422);
    }

    public function test_rejects_a_confirmation_when_the_superadmin_blocked_ai_chat_for_the_business(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['status' => 'confirmed'], 200)]);
        $business = Business::factory()->create(['ai_chat_blocked' => true]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/drafts/draft-1/confirm')
            ->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_admin_confirms_a_draft_and_the_context_is_built_correctly(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['status' => 'confirmed', 'data' => ['id' => 42]], 200)]);
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ai/drafts/draft-1/confirm');

        $response->assertOk()->assertJson(['status' => 'confirmed', 'data' => ['id' => 42]]);

        Http::assertSent(function ($request) use ($business, $admin) {
            return $request->url() === 'http://ia-core.test/v1/drafts/draft-1/confirm'
                && $request->hasHeader('Authorization', 'Bearer test-ia-core-key')
                && $request['context']['business_id'] === (string) $business->id
                && $request['context']['user_id'] === (string) $admin->id
                && ! array_key_exists('values', $request->data());
        });
    }

    public function test_forwards_edited_values_when_provided(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['status' => 'confirmed', 'data' => []], 200)]);
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/drafts/draft-1/confirm', ['values' => ['monto' => 5000]])
            ->assertOk();

        Http::assertSent(fn ($request) => $request['values'] === ['monto' => 5000]);
    }

    public function test_passes_through_a_404_when_the_draft_does_not_exist(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['detail' => 'Borrador no encontrado.'], 404)]);
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/drafts/no-existe/confirm')
            ->assertStatus(404)
            ->assertJsonPath('detail', 'Borrador no encontrado.');
    }

    public function test_admin_discards_a_draft(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['status' => 'discarded'], 200)]);
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/drafts/draft-1/discard')
            ->assertOk()
            ->assertJson(['status' => 'discarded']);

        Http::assertSent(fn ($request) => $request->url() === 'http://ia-core.test/v1/drafts/draft-1/discard');
    }

    public function test_returns_a_gateway_error_when_the_ia_core_is_unreachable(): void
    {
        config(['services.ia_core.base_url' => null]);
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/drafts/draft-1/confirm')
            ->assertStatus(502);
    }
}
