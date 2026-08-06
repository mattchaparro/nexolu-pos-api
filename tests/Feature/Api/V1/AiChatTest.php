<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cubre el unico punto de salida hacia el Nexolu IA Core: el POS arma el
 * TenantContext a partir del usuario Sanctum autenticado y proxea el
 * mensaje. No prueba el IA Core en si (repo aparte, Python) - eso lo cubre
 * la suite pytest de ese proyecto - solo que el POS le manda el contexto
 * correcto y maneja bien la respuesta/errores.
 */
class AiChatTest extends TestCase
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
            ->postJson('/api/v1/ai/chat', ['agent' => 'cajero', 'message' => 'Como esta la caja?'])
            ->assertStatus(422);
    }

    public function test_admin_can_send_a_message_and_the_context_is_built_correctly(): void
    {
        Http::fake([
            'ia-core.test/*' => Http::response([
                'conversation_id' => 'conv-1',
                'text' => 'La caja esta cerrada.',
                'tools_used' => ['estado_caja'],
                'drafts' => [],
            ], 200),
        ]);

        $business = Business::factory()->create(['feature_flags' => null]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ai/chat', [
            'agent' => 'cajero',
            'message' => 'Como esta la caja?',
        ]);

        $response->assertOk()->assertJsonPath('text', 'La caja esta cerrada.');

        Http::assertSent(function ($request) use ($business, $admin) {
            return $request->url() === 'http://ia-core.test/v1/chat'
                && $request->hasHeader('Authorization', 'Bearer test-ia-core-key')
                && $request['agent'] === 'cajero'
                && $request['message'] === 'Como esta la caja?'
                && $request['context']['business_id'] === (string) $business->id
                && $request['context']['user_id'] === (string) $admin->id
                && $request['context']['is_admin'] === true
                && in_array('cash_shift.manage', $request['context']['permissions'], true);
        });
    }

    public function test_an_employee_with_the_direct_permission_can_send_a_message(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['conversation_id' => 'c1', 'text' => 'ok'], 200)]);

        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $employee->syncPermissions(['ai_chat.use']);

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/v1/ai/chat', ['agent' => 'cajero', 'message' => 'Hola'])
            ->assertOk();
    }

    public function test_returns_a_gateway_error_when_the_ia_core_is_unreachable(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['detail' => 'proveedor no disponible'], 503)]);

        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/chat', ['agent' => 'cajero', 'message' => 'Hola'])
            ->assertStatus(502)
            ->assertJsonPath('error', 'proveedor no disponible');
    }

    public function test_validates_required_fields(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/chat', ['agent' => 'cajero'])
            ->assertStatus(422);
    }
}
