<?php

namespace Tests\Feature\Services\Ai\Insights;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Ai\Insights\PayablesSummaryInsight;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PayablesSummaryInsightTest extends TestCase
{
    use DatabaseTransactions;

    private function insight(): PayablesSummaryInsight
    {
        return app(PayablesSummaryInsight::class);
    }

    public function test_is_not_worth_showing_without_any_pending_purchases(): void
    {
        $business = Business::factory()->create();

        $data = $this->insight()->gatherData($business->id);

        $this->assertFalse($this->insight()->isWorthShowing($data));
    }

    public function test_sums_pending_balance_by_supplier(): void
    {
        $business = Business::factory()->create();
        $supplier = Supplier::factory()->create(['business_id' => $business->id, 'name' => 'Distribuidora ABC']);
        $purchase = Purchase::factory()->credit()->create(['business_id' => $business->id, 'supplier_id' => $supplier->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);
        $purchase->lines()->create([
            'product_id' => $product->id, 'quantity' => 1, 'unit_cost_cop' => 40000, 'line_total_cop' => 40000,
        ]);

        $data = $this->insight()->gatherData($business->id);

        $this->assertSame(40000.0, $data['total_balance']);
        $this->assertSame(1, $data['supplier_count']);
        $this->assertSame('Distribuidora ABC', $data['biggest_supplier']['supplier']);
    }

    public function test_a_fully_paid_purchase_does_not_count_toward_the_balance(): void
    {
        $business = Business::factory()->create();
        $purchase = Purchase::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);
        $purchase->lines()->create([
            'product_id' => $product->id, 'quantity' => 1, 'unit_cost_cop' => 40000, 'line_total_cop' => 40000,
        ]);

        $data = $this->insight()->gatherData($business->id);

        $this->assertSame(0.0, $data['total_balance']);
    }
}
