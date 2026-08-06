<?php

namespace Tests\Feature\Services;

use App\Models\WhatsAppUsageDaily;
use App\Services\WhatsApp\WhatsAppCostReporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WhatsAppCostReporterTest extends TestCase
{
    use DatabaseTransactions;

    private function reporter(): WhatsAppCostReporter
    {
        return app(WhatsAppCostReporter::class);
    }

    public function test_sums_cost_micros_within_the_period_in_usd(): void
    {
        WhatsAppUsageDaily::create([
            'business_id' => null, 'date' => '2026-08-02',
            'category' => WhatsAppUsageDaily::CATEGORY_UTILITY, 'messages_count' => 10, 'cost_micros' => 2_000_000,
        ]);
        WhatsAppUsageDaily::create([
            'business_id' => null, 'date' => '2026-08-15',
            'category' => WhatsAppUsageDaily::CATEGORY_MARKETING, 'messages_count' => 5, 'cost_micros' => 500_000,
        ]);
        // Fuera del rango consultado, no debe sumarse.
        WhatsAppUsageDaily::create([
            'business_id' => null, 'date' => '2026-07-31',
            'category' => WhatsAppUsageDaily::CATEGORY_UTILITY, 'messages_count' => 3, 'cost_micros' => 9_000_000,
        ]);

        $cost = $this->reporter()->costUsdForPeriod('2026-08-01', '2026-08-31');

        $this->assertSame(2.5, $cost);
    }

    public function test_returns_zero_when_there_is_no_usage_in_the_period(): void
    {
        $this->assertSame(0.0, $this->reporter()->costUsdForPeriod('2026-08-01', '2026-08-31'));
    }
}
