<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessWhatsAppInbound;
use App\Models\AiChannelIdentity;
use App\Models\Business;
use App\Models\User;
use App\Services\AiChatService;
use App\Services\WhatsApp\IdentityResolver;
use App\Services\WhatsApp\WhatsAppCloudClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessWhatsAppInboundTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ia_core.api_key' => 'test-ia-core-key',
            'services.ia_core.base_url' => 'http://ia-core.test',
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1234567890',
        ]);
    }

    private function linkedUser(): User
    {
        $business = Business::factory()->create(['feature_flags' => null]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        AiChannelIdentity::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'external_id' => '573001234567',
            'verified_at' => now(),
        ]);

        return $user;
    }

    public function test_sends_the_onboarding_message_when_the_number_is_not_linked(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        (new ProcessWhatsAppInbound('573009999999', 'Hola'))->handle(
            app(IdentityResolver::class),
            app(AiChatService::class),
            app(WhatsAppCloudClient::class),
        );

        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'vincula'));
    }

    public function test_proxies_the_message_to_ia_core_and_replies_with_the_text(): void
    {
        $user = $this->linkedUser();

        Http::fake([
            'ia-core.test/*' => Http::response([
                'conversation_id' => 'conv-1',
                'text' => 'La caja esta cerrada.',
                'tools_used' => [],
                'drafts' => [],
            ], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.2']]], 200),
        ]);

        (new ProcessWhatsAppInbound('573001234567', 'Como esta la caja?', 'wamid.in'))->handle(
            app(IdentityResolver::class),
            app(AiChatService::class),
            app(WhatsAppCloudClient::class),
        );

        Http::assertSent(fn ($request) => $request->url() === 'http://ia-core.test/v1/chat'
            && $request['context']['business_id'] === (string) $user->business_id
            && $request['context']['channel'] === 'whatsapp');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && ($request['text']['body'] ?? null) === 'La caja esta cerrada.');

        $this->assertSame('conv-1', Cache::get("wa_conversation:{$user->id}"));
        $this->assertNull(Auth::user());
    }

    public function test_reuses_the_cached_conversation_id_on_the_next_message(): void
    {
        $user = $this->linkedUser();
        Cache::put("wa_conversation:{$user->id}", 'conv-existing', now()->addHour());

        Http::fake([
            'ia-core.test/*' => Http::response(['conversation_id' => 'conv-existing', 'text' => 'ok', 'drafts' => []], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.3']]], 200),
        ]);

        (new ProcessWhatsAppInbound('573001234567', 'Otra pregunta'))->handle(
            app(IdentityResolver::class),
            app(AiChatService::class),
            app(WhatsAppCloudClient::class),
        );

        Http::assertSent(fn ($request) => ($request['conversation_id'] ?? null) === 'conv-existing');
    }

    public function test_appends_a_draft_notice_when_the_ia_core_returns_a_pending_draft(): void
    {
        $this->linkedUser();

        Http::fake([
            'ia-core.test/*' => Http::response([
                'conversation_id' => 'conv-1',
                'text' => 'Voy a registrar el gasto.',
                'drafts' => [[
                    'id' => 'd1', 'tool_type' => 'crear_gasto', 'status' => 'pending',
                    'summary' => 'Gasto de $50.000', 'fields' => [], 'values' => [],
                ]],
            ], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.4']]], 200),
        ]);

        (new ProcessWhatsAppInbound('573001234567', 'Registra un gasto de 50 mil'))->handle(
            app(IdentityResolver::class),
            app(AiChatService::class),
            app(WhatsAppCloudClient::class),
        );

        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'borrador pendiente'));
    }

    public function test_sends_a_whatsapp_flow_for_a_pending_expense_draft_when_configured(): void
    {
        $user = $this->linkedUser();
        config(['services.whatsapp.flows.gasto' => ['id' => 'flow-123', 'screen' => 'GASTO']]);

        Http::fake([
            'ia-core.test/*' => Http::response([
                'conversation_id' => 'conv-1',
                'text' => 'Voy a registrar el gasto.',
                'drafts' => [[
                    'id' => 'draft-1', 'tool_type' => 'crear_gasto', 'status' => 'pending',
                    'summary' => 'Gasto de $50.000',
                    'fields' => [],
                    'values' => ['concepto' => 'Papeleria', 'monto' => 50000, 'fecha' => '2026-08-06'],
                ]],
            ], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.4']]], 200),
        ]);

        (new ProcessWhatsAppInbound('573001234567', 'Registra un gasto de 50 mil'))->handle(
            app(IdentityResolver::class),
            app(AiChatService::class),
            app(WhatsAppCloudClient::class),
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'graph.facebook.com')) {
                return false;
            }
            $params = $request['interactive']['action']['parameters'] ?? null;

            return $params !== null
                && $params['flow_id'] === 'flow-123'
                && $params['flow_token'] === 'draft-1'
                && $params['flow_action_payload']['data']['concepto'] === 'Papeleria'
                && (float) $params['flow_action_payload']['data']['monto'] === 50000.0;
        });

        // El texto del modelo no se manda por separado: el Flow ya es la respuesta.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && ($request['type'] ?? null) === 'text');
    }

    public function test_replies_with_the_error_message_when_the_ia_core_is_unreachable(): void
    {
        $this->linkedUser();

        Http::fake([
            'ia-core.test/*' => Http::response(['detail' => 'proveedor no disponible'], 503),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.5']]], 200),
        ]);

        (new ProcessWhatsAppInbound('573001234567', 'Hola'))->handle(
            app(IdentityResolver::class),
            app(AiChatService::class),
            app(WhatsAppCloudClient::class),
        );

        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'proveedor no disponible'));
    }
}
