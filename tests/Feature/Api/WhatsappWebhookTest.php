<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessWhatsAppInbound;
use App\Jobs\ProcessWhatsAppUnsupportedMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsappWebhookTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.verify_token' => 'test-verify-token']);
    }

    public function test_verify_returns_the_challenge_when_the_token_matches(): void
    {
        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=test-verify-token&hub_challenge=123456')
            ->assertOk()
            ->assertSee('123456');
    }

    public function test_verify_rejects_a_wrong_token(): void
    {
        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=123456')
            ->assertStatus(403);
    }

    public function test_handle_dispatches_the_inbound_job_for_a_text_message(): void
    {
        Queue::fake();

        $payload = $this->textMessagePayload('573001234567', 'wamid.1', 'Hola');

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        Queue::assertPushed(ProcessWhatsAppInbound::class, fn ($job) => $job->from === '573001234567');
    }

    public function test_handle_deduplicates_the_same_wamid(): void
    {
        Queue::fake();

        $payload = $this->textMessagePayload('573001234567', 'wamid.dup', 'Hola');

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();
        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        Queue::assertPushed(ProcessWhatsAppInbound::class, 1);
    }

    public function test_handle_dispatches_the_unsupported_message_job_for_an_image(): void
    {
        Queue::fake();

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.img',
                            'from' => '573001234567',
                            'type' => 'image',
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        Queue::assertPushed(ProcessWhatsAppUnsupportedMessage::class, fn ($job) => $job->from === '573001234567' && $job->type === 'image');
    }

    public function test_handle_ignores_a_list_reply_titled_message_by_forwarding_it_as_text(): void
    {
        Queue::fake();

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.list',
                            'from' => '573001234567',
                            'type' => 'interactive',
                            'interactive' => [
                                'type' => 'list_reply',
                                'list_reply' => ['title' => 'Opcion elegida'],
                            ],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk();

        Queue::assertPushed(ProcessWhatsAppInbound::class, fn ($job) => $job->text === 'Opcion elegida');
    }

    public function test_handle_always_returns_200_even_with_an_empty_payload(): void
    {
        $this->postJson('/api/webhooks/whatsapp', [])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function textMessagePayload(string $from, string $wamid, string $body): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => $wamid,
                            'from' => $from,
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]],
        ];
    }
}
