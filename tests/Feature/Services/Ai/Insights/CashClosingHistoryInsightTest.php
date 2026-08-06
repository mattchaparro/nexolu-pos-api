<?php

namespace Tests\Feature\Services\Ai\Insights;

use App\Models\Business;
use App\Models\CashClosing;
use App\Services\Ai\Insights\CashClosingHistoryInsight;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CashClosingHistoryInsightTest extends TestCase
{
    use DatabaseTransactions;

    private function insight(): CashClosingHistoryInsight
    {
        return app(CashClosingHistoryInsight::class);
    }

    public function test_is_not_worth_showing_without_any_closings(): void
    {
        $business = Business::factory()->create();

        $data = $this->insight()->gatherData($business->id);

        $this->assertFalse($this->insight()->isWorthShowing($data));
    }

    public function test_counts_balanced_and_short_closings(): void
    {
        $business = Business::factory()->create();
        CashClosing::factory()->create(['business_id' => $business->id, 'date' => now()->toDateString(), 'difference' => 0]);
        CashClosing::factory()->create(['business_id' => $business->id, 'date' => now()->subDay()->toDateString(), 'difference' => -5000]);
        CashClosing::factory()->create(['business_id' => $business->id, 'date' => now()->subDays(2)->toDateString(), 'difference' => -2000]);

        $data = $this->insight()->gatherData($business->id);

        $this->assertSame(3, $data['total']);
        $this->assertSame(1, $data['balanced']);
        $this->assertSame(2, $data['short']);
        $this->assertSame(-7000.0, $data['total_shortfall']);
        $this->assertTrue($this->insight()->isWorthShowing($data));
    }
}
