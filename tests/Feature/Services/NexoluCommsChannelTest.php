<?php

namespace Tests\Feature\Services;

use App\Services\WhatsApp\NexoluCommsChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NexoluCommsChannelTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.comms_core.api_key' => 'test-comms-key',
            'services.comms_core.base_url' => 'https://comms.nexolu.test',
        ]);
    }

    public function test_is_not_configured_without_credentials(): void
    {
        config(['services.comms_core.api_key' => null]);

        $this->assertFalse(app(NexoluCommsChannel::class)->isConfigured());
    }

    public function test_send_text_posts_to_the_notifications_endpoint(): void
    {
        Http::fake([
            'comms.nexolu.test/*' => Http::response([
                'reference' => null,
                'business_id' => 'pos',
                'results' => [['channel' => 'whatsapp', 'status' => 'sent', 'provider_message_id' => 'wamid.123', 'cost_micros' => null, 'error' => null]],
            ], 200),
        ]);

        $sent = app(NexoluCommsChannel::class)->sendText('573001234567', 'Hola!');

        $this->assertTrue($sent);

        Http::assertSent(fn ($request) => $request->url() === 'https://comms.nexolu.test/v1/notifications/send'
            && $request->hasHeader('Authorization', 'Bearer test-comms-key')
            && $request['channels'] === ['whatsapp']
            && $request['to']['whatsapp'] === '573001234567'
            && $request['text'] === 'Hola!');
    }

    public function test_send_template_posts_the_whatsapp_template_payload(): void
    {
        Http::fake(['comms.nexolu.test/*' => Http::response([
            'reference' => null, 'business_id' => 'pos',
            'results' => [['channel' => 'whatsapp', 'status' => 'sent', 'provider_message_id' => 'wamid.456', 'cost_micros' => null, 'error' => null]],
        ], 200)]);

        $sent = app(NexoluCommsChannel::class)->sendTemplate('573001234567', 'welcome_whatsapp_linked', 'es_CO');

        $this->assertTrue($sent);

        Http::assertSent(fn ($request) => $request['whatsapp_template']['name'] === 'welcome_whatsapp_linked'
            && $request['whatsapp_template']['language'] === 'es_CO');
    }

    public function test_send_flow_posts_the_whatsapp_flow_payload(): void
    {
        Http::fake(['comms.nexolu.test/*' => Http::response([
            'reference' => null, 'business_id' => 'pos',
            'results' => [['channel' => 'whatsapp', 'status' => 'sent', 'provider_message_id' => 'wamid.999', 'cost_micros' => null, 'error' => null]],
        ], 200)]);

        $sent = app(NexoluCommsChannel::class)->sendFlow(
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
            $flow = $request['whatsapp_flow'];

            return $request['text'] === 'Confirma el gasto'
                && $flow['flow_id'] === 'flow-id-123'
                && $flow['flow_token'] === 'draft-abc'
                && $flow['data']['description'] === 'Papeleria';
        });
    }

    public function test_returns_false_when_the_whatsapp_channel_result_is_not_sent(): void
    {
        Http::fake(['comms.nexolu.test/*' => Http::response([
            'reference' => null, 'business_id' => 'pos',
            'results' => [['channel' => 'whatsapp', 'status' => 'skipped', 'provider_message_id' => null, 'cost_micros' => null, 'error' => 'WhatsApp no configurado para esta app.']],
        ], 200)]);

        $sent = app(NexoluCommsChannel::class)->sendText('573001234567', 'Hola!');

        $this->assertFalse($sent);
    }

    public function test_returns_false_without_calling_comms_when_not_configured(): void
    {
        config(['services.comms_core.api_key' => null]);
        Http::fake();

        $sent = app(NexoluCommsChannel::class)->sendText('573001234567', 'Hola!');

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }

    public function test_returns_false_when_comms_rejects_the_request(): void
    {
        Http::fake(['comms.nexolu.test/*' => Http::response(['detail' => 'invalid'], 422)]);

        $sent = app(NexoluCommsChannel::class)->sendText('573001234567', 'Hola!');

        $this->assertFalse($sent);
    }

    public function test_mark_as_read_with_typing_posts_to_the_read_receipt_endpoint(): void
    {
        Http::fake(['comms.nexolu.test/*' => Http::response(['status' => 'sent'], 200)]);

        $result = app(NexoluCommsChannel::class)->markAsReadWithTyping('573001234567', 'wamid.789');

        $this->assertTrue($result);

        Http::assertSent(fn ($request) => $request->url() === 'https://comms.nexolu.test/v1/whatsapp/read-receipt'
            && $request['to'] === '573001234567'
            && $request['message_id'] === 'wamid.789');
    }

    public function test_mark_as_read_with_typing_returns_false_when_skipped(): void
    {
        Http::fake(['comms.nexolu.test/*' => Http::response(['status' => 'skipped'], 200)]);

        $result = app(NexoluCommsChannel::class)->markAsReadWithTyping('573001234567', 'wamid.789');

        $this->assertFalse($result);
    }
}
