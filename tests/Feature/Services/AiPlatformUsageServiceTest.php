<?php

namespace Tests\Feature\Services;

use App\Services\AiPlatformUsageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiPlatformUsageServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ia_core.base_url' => 'http://ia-core.test',
            'services.ia_core.platform_api_key' => 'test-platform-key',
        ]);
    }

    private function service(): AiPlatformUsageService
    {
        return app(AiPlatformUsageService::class);
    }

    public function test_returns_null_without_a_platform_key_configured(): void
    {
        config(['services.ia_core.platform_api_key' => null]);
        Http::fake();

        $this->assertNull($this->service()->costUsdForPeriod('2026-08-01', '2026-08-31'));
        Http::assertNothingSent();
    }

    public function test_returns_the_cost_for_the_pos_app_from_the_breakdown(): void
    {
        Http::fake(['ia-core.test/*' => Http::response([
            'breakdown' => [
                ['key' => 'pos', 'message_count' => 10, 'input_tokens' => 100, 'output_tokens' => 50, 'cost_usd' => 2.35],
                ['key' => 'spa', 'message_count' => 3, 'input_tokens' => 30, 'output_tokens' => 10, 'cost_usd' => 0.5],
            ],
        ], 200)]);

        $cost = $this->service()->costUsdForPeriod('2026-08-01', '2026-08-31');

        $this->assertSame(2.35, $cost);
        Http::assertSent(fn ($request) => $request->url() === 'http://ia-core.test/v1/platform/usage?date_from=2026-08-01&date_to=2026-08-31'
            && $request->hasHeader('Authorization', 'Bearer test-platform-key'));
    }

    public function test_returns_zero_when_the_pos_app_has_no_usage_yet(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['breakdown' => [['key' => 'spa', 'cost_usd' => 0.5]]], 200)]);

        $this->assertSame(0.0, $this->service()->costUsdForPeriod('2026-08-01', '2026-08-31'));
    }

    public function test_returns_null_when_the_ia_core_is_unreachable(): void
    {
        Http::fake(['ia-core.test/*' => Http::response(['detail' => 'no autorizado'], 401)]);

        $this->assertNull($this->service()->costUsdForPeriod('2026-08-01', '2026-08-31'));
    }
}
