<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessWhatsAppInbound;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cubre el webhook publico y firmado que Nexolu Communications (repo Python
 * aparte, reenvio de eventos de WhatsApp) usa para entregar el evento crudo
 * de Meta. Mismo esquema HMAC que PaymentsCoreWebhookTest - la unica
 * diferencia real de comportamiento es que, con firma valida, delega en
 * InboundMessageDispatcher (mismo camino que WhatsappWebhookController).
 */
class NexoluCommsWebhookTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'test-comms-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.comms_core.webhook_secret' => self::SECRET]);
    }

    private function sign(string $body, string $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);
    }

    private function postSigned(array $payload): TestResponse
    {
        $body = json_encode($payload);
        $timestamp = (string) now()->timestamp;

        return $this->call(
            'POST',
            '/api/webhooks/nexolu-comms/whatsapp',
            [],
            [],
            [],
            [
                'HTTP_X-Nexolu-Timestamp' => $timestamp,
                'HTTP_X-Nexolu-Signature' => $this->sign($body, $timestamp),
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );
    }

    public function test_rejects_a_request_without_a_valid_signature(): void
    {
        $this->postJson('/api/webhooks/nexolu-comms/whatsapp', ['entry' => []])
            ->assertStatus(401);
    }

    public function test_rejects_a_wrong_signature(): void
    {
        $body = json_encode(['entry' => []]);

        $this->call('POST', '/api/webhooks/nexolu-comms/whatsapp', [], [], [], [
            'HTTP_X-Nexolu-Timestamp' => (string) now()->timestamp,
            'HTTP_X-Nexolu-Signature' => 'wrong',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);
    }

    public function test_dispatches_the_inbound_job_for_a_signed_text_message(): void
    {
        Queue::fake();

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.comms-1',
                            'from' => '573001234567',
                            'type' => 'text',
                            'text' => ['body' => 'Hola desde comms'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postSigned($payload)->assertOk()->assertJson(['ok' => true]);

        Queue::assertPushed(ProcessWhatsAppInbound::class, fn ($job) => $job->from === '573001234567' && $job->text === 'Hola desde comms');
    }

    public function test_returns_401_when_no_secret_is_configured(): void
    {
        config(['services.comms_core.webhook_secret' => null]);

        $this->postSigned(['entry' => []])->assertStatus(401);
    }
}
