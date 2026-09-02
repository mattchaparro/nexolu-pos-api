<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Variante streaming de AiChatTest: mismo gate de permiso/negocio/cuota que
 * /v1/ai/chat, pero la respuesta se relayea byte a byte desde IA Core como
 * Server-Sent Events. El controller es un cano tonto - no parsea el SSE -
 * asi que lo que se prueba aca es que el passthrough sea fiel (incluido el
 * caso de un evento de error en banda, que IA Core manda con HTTP 200) y que
 * el gate de acceso siga corriendo antes de abrir el stream.
 */
class AiChatStreamTest extends TestCase
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
            ->postJson('/api/v1/ai/chat/stream', ['agent' => 'cajero', 'message' => 'Como esta la caja?'])
            ->assertStatus(422);
    }

    public function test_rejects_a_message_when_the_superadmin_blocked_ai_chat_for_the_business(): void
    {
        Http::fake(['ia-core.test/*' => Http::response('no deberia llegar aca', 200)]);

        $business = Business::factory()->create(['ai_chat_blocked' => true]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/chat/stream', ['agent' => 'cajero', 'message' => 'Hola'])
            ->assertStatus(403);

        Http::assertNothingSent();
    }

    /**
     * Sin esto, un hueco de cobertura solo se descubre si alguien manda una
     * captura de pantalla. Fue exactamente lo que paso con el chat del
     * legacy: el unico rastro de "no tengo herramienta para crear
     * proveedores" quedo en esta misma tabla.
     */
    public function test_a_reply_without_tools_is_recorded_as_an_unanswered_question(): void
    {
        $sse = 'data: '.json_encode([
            'delta' => null,
            'done' => true,
            'conversation_id' => 'conv-1',
            'text' => 'No tengo una herramienta para crear proveedores.',
            'tools_used' => [],
            'drafts' => [],
        ])."\n\n";

        Http::fake(['ia-core.test/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/chat/stream', [
                'agent' => 'asistente',
                'message' => 'Crea un proveedor llamado Postobon',
            ])
            ->assertOk()
            ->streamedContent();

        $this->assertDatabaseHas('ai_unanswered_questions', [
            'business_id' => $business->id,
            'user_id' => $admin->id,
            'pregunta' => 'Crea un proveedor llamado Postobon',
        ]);
    }

    /** Una respuesta que SI uso una herramienta no es un hueco de cobertura. */
    public function test_a_reply_that_used_tools_is_not_recorded(): void
    {
        $sse = 'data: '.json_encode([
            'delta' => null,
            'done' => true,
            'conversation_id' => 'conv-2',
            'text' => 'Hoy vendiste $50.000.',
            'tools_used' => ['ventas_resumen'],
            'drafts' => [],
        ])."\n\n";

        Http::fake(['ia-core.test/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/chat/stream', [
                'agent' => 'asistente',
                'message' => 'Cuanto vendi hoy?',
            ])
            ->assertOk()
            ->streamedContent();

        $this->assertDatabaseCount('ai_unanswered_questions', 0);
    }

    public function test_admin_can_stream_a_message_and_the_context_is_built_correctly(): void
    {
        $sse = 'data: '.json_encode(['delta' => 'La caja ', 'done' => false])."\n\n"
            .'data: '.json_encode([
                'delta' => null,
                'done' => true,
                'conversation_id' => 'conv-1',
                'text' => 'La caja esta cerrada.',
                'tools_used' => ['estado_caja'],
                'drafts' => [],
            ])."\n\n";

        Http::fake([
            'ia-core.test/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $business = Business::factory()->create(['feature_flags' => null]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ai/chat/stream', [
            'agent' => 'cajero',
            'message' => 'Como esta la caja?',
        ]);

        $response->assertOk();
        // Solo el media type: el charset lo agrega el framework, no el
        // controlador (que fija 'text/event-stream' a secas), y su
        // capitalizacion cambia entre versiones - comparar la cabecera
        // entera hacia fallar el test segun donde corriera.
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $this->assertSame($sse, $response->streamedContent());

        Http::assertSent(function ($request) use ($business, $admin) {
            return $request->url() === 'http://ia-core.test/v1/chat/stream'
                && $request->hasHeader('Authorization', 'Bearer test-ia-core-key')
                && $request['agent'] === 'cajero'
                && $request['message'] === 'Como esta la caja?'
                && $request['context']['business_id'] === (string) $business->id
                && $request['context']['user_id'] === (string) $admin->id
                && $request['context']['is_admin'] === true;
        });
    }

    public function test_an_employee_with_the_direct_permission_can_stream_a_message(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(
            'data: '.json_encode(['delta' => null, 'done' => true, 'conversation_id' => 'c1', 'text' => 'ok'])."\n\n",
            200,
            ['Content-Type' => 'text/event-stream']
        )]);

        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $employee->syncPermissions(['ai_chat.use']);

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/v1/ai/chat/stream', ['agent' => 'cajero', 'message' => 'Hola'])
            ->assertOk();
    }

    public function test_relays_an_in_band_error_event_without_altering_it(): void
    {
        $sse = 'data: '.json_encode(['error' => 'mensaje vacio'])."\n\n";

        Http::fake(['ia-core.test/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ai/chat/stream', [
            'agent' => 'cajero',
            'message' => 'Hola',
        ]);

        $response->assertOk();
        $this->assertSame($sse, $response->streamedContent());
    }

    public function test_returns_a_gateway_error_when_the_ia_core_is_unreachable(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['detail' => 'proveedor no disponible'], 503)]);

        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/chat/stream', ['agent' => 'cajero', 'message' => 'Hola'])
            ->assertStatus(502)
            ->assertJsonPath('error', 'proveedor no disponible');
    }

    public function test_validates_required_fields(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/chat/stream', ['agent' => 'cajero'])
            ->assertStatus(422);
    }
}
