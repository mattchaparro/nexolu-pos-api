<?php

namespace Tests\Feature\Services;

use App\Models\WhatsAppUsageDaily;
use App\Services\WhatsApp\WhatsAppCloudClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppCloudClientTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.graph_version' => 'v25.0',
        ]);
    }

    public function test_is_not_configured_without_credentials(): void
    {
        config(['services.whatsapp.access_token' => null]);

        $this->assertFalse(app(WhatsAppCloudClient::class)->isConfigured());
    }

    public function test_send_text_posts_to_the_graph_api_and_records_usage_as_service(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]], 200),
        ]);

        $sent = app(WhatsAppCloudClient::class)->sendText('573001234567', 'Hola!');

        $this->assertTrue($sent);

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v25.0/1234567890/messages'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['type'] === 'text'
            && $request['text']['body'] === 'Hola!');

        $this->assertDatabaseHas('whatsapp_usage_daily', [
            'category' => WhatsAppUsageDaily::CATEGORY_SERVICE,
            'messages_count' => 1,
        ]);
    }

    public function test_send_template_posts_the_template_payload(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.456']]], 200)]);

        $sent = app(WhatsAppCloudClient::class)->sendTemplate('573001234567', 'welcome_whatsapp_linked', 'es_CO');

        $this->assertTrue($sent);

        Http::assertSent(fn ($request) => $request['type'] === 'template'
            && $request['template']['name'] === 'welcome_whatsapp_linked'
            && $request['template']['language']['code'] === 'es_CO');
    }

    public function test_send_document_posts_the_document_payload(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.789']]], 200)]);

        $sent = app(WhatsAppCloudClient::class)->sendDocument(
            '573001234567', 'https://pos.nexolu.co/api/public/receipts/sale/1?signature=abc', 'recibo-1.pdf', 'Tu recibo',
        );

        $this->assertTrue($sent);

        Http::assertSent(fn ($request) => $request['type'] === 'document'
            && $request['document']['link'] === 'https://pos.nexolu.co/api/public/receipts/sale/1?signature=abc'
            && $request['document']['filename'] === 'recibo-1.pdf'
            && $request['document']['caption'] === 'Tu recibo');
    }

    public function test_returns_false_and_does_not_record_usage_when_meta_rejects_the_message(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => 'invalid'], 400)]);

        $sent = app(WhatsAppCloudClient::class)->sendText('573001234567', 'Hola!');

        $this->assertFalse($sent);
        $this->assertDatabaseCount('whatsapp_usage_daily', 0);
    }

    public function test_returns_false_without_calling_meta_when_not_configured(): void
    {
        config(['services.whatsapp.access_token' => null]);
        Http::fake();

        $sent = app(WhatsAppCloudClient::class)->sendText('573001234567', 'Hola!');

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }

    public function test_send_flow_posts_the_interactive_flow_payload(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.999']]], 200)]);

        $sent = app(WhatsAppCloudClient::class)->sendFlow(
            '573001234567',
            'flow-id-123',
            'SCREEN_ONE',
            'Confirma el gasto',
            'Confirmar',
            ['description' => 'Papeleria', 'value' => 25000],
            'draft-abc',
        );

        $this->assertTrue($sent);

        Http::assertSent(function ($request) {
            $params = $request['interactive']['action']['parameters'];

            return $request['type'] === 'interactive'
                && $request['interactive']['type'] === 'flow'
                && $params['flow_id'] === 'flow-id-123'
                && $params['flow_token'] === 'draft-abc'
                && $params['flow_action_payload']['screen'] === 'SCREEN_ONE'
                && $params['flow_action_payload']['data']['description'] === 'Papeleria';
        });
    }

    public function test_mark_as_read_with_typing_does_not_record_usage(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([], 200)]);

        $result = app(WhatsAppCloudClient::class)->markAsReadWithTyping('573001234567', 'wamid.789');

        $this->assertTrue($result);
        $this->assertDatabaseCount('whatsapp_usage_daily', 0);
    }
}
