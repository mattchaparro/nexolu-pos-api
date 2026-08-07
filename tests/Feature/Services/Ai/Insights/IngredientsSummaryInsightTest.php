<?php

namespace Tests\Feature\Services\Ai\Insights;

use App\Models\Business;
use App\Models\Ingredient;
use App\Services\Ai\Insights\IngredientsSummaryInsight;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IngredientsSummaryInsightTest extends TestCase
{
    use DatabaseTransactions;

    private function insight(): IngredientsSummaryInsight
    {
        return app(IngredientsSummaryInsight::class);
    }

    public function test_is_not_worth_showing_without_any_ingredients(): void
    {
        $business = Business::factory()->create();

        $data = $this->insight()->gatherData($business->id);

        $this->assertFalse($this->insight()->isWorthShowing($data));
    }

    public function test_counts_ingredients_below_minimum_without_flagging_a_most_urgent_one_when_theres_no_recent_consumption(): void
    {
        $business = Business::factory()->create();
        Ingredient::factory()->create(['business_id' => $business->id, 'is_active' => true, 'stock' => 10, 'min_stock' => 0]);
        Ingredient::factory()->create(['business_id' => $business->id, 'is_active' => true, 'stock' => 1, 'min_stock' => 5]);

        $data = $this->insight()->gatherData($business->id);

        $this->assertSame(2, $data['total_ingredients']);
        $this->assertSame(1, $data['below_minimum_count']);
        $this->assertNull($data['most_urgent_ingredient']);
        $this->assertSame(1, $data['no_movement_count']);
        $this->assertNull($this->insight()->suggestedAction($data));
    }

    public function test_inventory_value_sums_stock_times_cost_price(): void
    {
        $business = Business::factory()->create();
        Ingredient::factory()->create([
            'business_id' => $business->id, 'is_active' => true, 'stock' => 10, 'cost_price' => 500, 'min_stock' => 0,
        ]);

        $data = $this->insight()->gatherData($business->id);

        $this->assertSame(5000.0, $data['inventory_value']);
    }
}
