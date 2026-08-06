<?php

namespace Tests\Feature\Services\Ai\Insights;

use App\Models\Business;
use App\Models\Receivable;
use App\Services\Ai\Insights\ReceivablesSummaryInsight;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReceivablesSummaryInsightTest extends TestCase
{
    use DatabaseTransactions;

    private function insight(): ReceivablesSummaryInsight
    {
        return app(ReceivablesSummaryInsight::class);
    }

    public function test_is_not_worth_showing_without_any_balance_owed(): void
    {
        $business = Business::factory()->create();

        $data = $this->insight()->gatherData($business->id);

        $this->assertFalse($this->insight()->isWorthShowing($data));
    }

    public function test_identifies_the_biggest_and_oldest_debtor(): void
    {
        $business = Business::factory()->create();
        Receivable::factory()->create(['business_id' => $business->id, 'customer_name' => 'Ana', 'balance' => 10000]);
        $biggest = Receivable::factory()->create(['business_id' => $business->id, 'customer_name' => 'Beto', 'balance' => 90000]);
        $biggest->forceFill(['created_at' => now()->subDays(2)])->save();

        $data = $this->insight()->gatherData($business->id);

        $this->assertSame(100000.0, $data['total_balance']);
        $this->assertSame(2, $data['debtor_count']);
        $this->assertSame('Beto', $data['biggest_debtor']['customer']);
    }

    public function test_ignores_receivables_already_paid(): void
    {
        $business = Business::factory()->create();
        Receivable::factory()->paid()->create(['business_id' => $business->id]);

        $data = $this->insight()->gatherData($business->id);

        $this->assertSame(0.0, $data['total_balance']);
    }
}
