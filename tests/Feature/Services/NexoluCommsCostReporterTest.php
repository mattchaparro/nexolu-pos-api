<?php

namespace Tests\Feature\Services;

use App\Services\WhatsApp\NexoluCommsCostReporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NexoluCommsCostReporterTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.comms_core.base_url' => 'https://comms.nexolu.test',
            'services.comms_core.api_key' => 'test-comms-key',
        ]);
    }

    private function reporter(): NexoluCommsCostReporter
    {
        return app(NexoluCommsCostReporter::class);
    }

    public function test_returns_null_without_credentials_configured(): void
    {
        config(['services.comms_core.api_key' => null]);
        Http::fake();

        $this->assertNull($this->reporter()->costUsdForPeriod('2026-08-01', '2026-08-31'));
        Http::assertNothingSent();
    }

    public function test_returns_the_cost_from_the_usage_summary(): void
    {
        Http::fake(['comms.nexolu.test/*' => Http::response([
            'date_from' => '2026-08-01', 'date_to' => '2026-08-31',
            'summary' => ['message_count' => 42, 'cost_usd' => 3.75],
        ], 200)]);

        $cost = $this->reporter()->costUsdForPeriod('2026-08-01', '2026-08-31');

        $this->assertSame(3.75, $cost);
        Http::assertSent(fn ($request) => $request->url() === 'https://comms.nexolu.test/v1/usage/summary?channel=whatsapp&date_from=2026-08-01&date_to=2026-08-31'
            && $request->hasHeader('Authorization', 'Bearer test-comms-key'));
    }

    public function test_returns_null_when_nexolu_communications_is_unreachable(): void
    {
        Http::fake(['comms.nexolu.test/*' => Http::response(['detail' => 'no autorizado'], 401)]);

        $this->assertNull($this->reporter()->costUsdForPeriod('2026-08-01', '2026-08-31'));
    }
}
