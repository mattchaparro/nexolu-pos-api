<?php

namespace Tests\Feature\Services\Ai\Insights;

use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Services\Ai\Insights\ExpensesSummaryInsight;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpensesSummaryInsightTest extends TestCase
{
    use DatabaseTransactions;

    private function insight(): ExpensesSummaryInsight
    {
        return app(ExpensesSummaryInsight::class);
    }

    public function test_is_not_worth_showing_without_any_expenses(): void
    {
        $business = Business::factory()->create();

        $data = $this->insight()->gatherData($business->id);

        $this->assertFalse($this->insight()->isWorthShowing($data));
    }

    public function test_totals_this_month_and_top_three_categories(): void
    {
        $business = Business::factory()->create();
        $type = ExpenseType::factory()->create(['business_id' => $business->id, 'name' => 'Insumos']);
        Expense::factory()->create([
            'business_id' => $business->id, 'type_id' => $type->id, 'date' => now()->toDateString(), 'value' => 30000,
        ]);
        Expense::factory()->create([
            'business_id' => $business->id, 'type_id' => $type->id, 'date' => now()->toDateString(), 'value' => 20000,
        ]);

        $data = $this->insight()->gatherData($business->id);

        $this->assertSame(50000.0, $data['total_this_month']);
        $this->assertSame('Insumos', $data['top_categories'][0]['type']);
        $this->assertSame(50000.0, $data['top_categories'][0]['total']);
        $this->assertTrue($this->insight()->isWorthShowing($data));
    }
}
