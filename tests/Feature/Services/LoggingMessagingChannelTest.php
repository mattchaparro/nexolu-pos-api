<?php

namespace Tests\Feature\Services;

use App\Models\Business;
use App\Models\WhatsappLog;
use App\Services\Messaging\Contracts\MessagingChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MessagingChannel::class resuelve a LoggingMessagingChannel (ver
 * AppServiceProvider) envolviendo WhatsAppCloudClient - estos tests cubren
 * el decorator via el binding real, no la clase directamente.
 */
class LoggingMessagingChannelTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1234567890',
        ]);
    }

    public function test_a_successful_send_is_logged_with_business_and_type(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        $business = Business::factory()->create();

        $sent = app(MessagingChannel::class)->sendText('573001234567', 'Hola!', $business->id, 'recordatorio');

        $this->assertTrue($sent);
        $this->assertDatabaseHas('whatsapp_logs', [
            'business_id' => $business->id,
            'type' => 'recordatorio',
            'to_phone' => '573001234567',
            'status' => WhatsappLog::STATUS_SENT,
        ]);
    }

    public function test_a_failed_send_is_logged_as_failed(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'rejected']], 400)]);

        $sent = app(MessagingChannel::class)->sendText('573001234567', 'Hola!', null, 'otp');

        $this->assertFalse($sent);
        $this->assertDatabaseHas('whatsapp_logs', [
            'business_id' => null,
            'type' => 'otp',
            'to_phone' => '573001234567',
            'status' => WhatsappLog::STATUS_FAILED,
        ]);
    }

    public function test_type_defaults_to_generico_when_not_declared(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        app(MessagingChannel::class)->sendText('573001234567', 'Hola!');

        $this->assertDatabaseHas('whatsapp_logs', ['type' => 'generico']);
    }

    public function test_a_read_receipt_is_not_logged(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

        app(MessagingChannel::class)->markAsReadWithTyping('573001234567', 'wamid.1');

        $this->assertDatabaseCount('whatsapp_logs', 0);
    }
}
