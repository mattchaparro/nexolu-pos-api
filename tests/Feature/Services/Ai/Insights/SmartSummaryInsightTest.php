<?php

namespace Tests\Feature\Services\Ai\Insights;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\Ai\Insights\SmartSummaryInsight;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SmartSummaryInsightTest extends TestCase
{
    use DatabaseTransactions;

    private function insight(): SmartSummaryInsight
    {
        return app(SmartSummaryInsight::class);
    }

    public function test_is_not_worth_showing_with_no_activity_and_green_health(): void
    {
        $business = Business::factory()->create(['feature_flags' => []]);

        $data = $this->insight()->gatherData($business->id);

        // El factor de inventario se evalua siempre (sin gate de feature):
        // sin productos bajos, ya es un factor verde por si solo.
        $this->assertSame('green', $data['health_level']);
        $this->assertSame('inventario sin urgencias', $data['health_factor']);
        $this->assertNull($data['priority']);
        $this->assertFalse($this->insight()->isWorthShowing($data));
    }

    public function test_computes_sales_today_and_comparison_against_yesterday_same_cutoff(): void
    {
        $business = Business::factory()->create(['feature_flags' => []]);

        Sale::factory()->create(['business_id' => $business->id, 'total' => 100000, 'closed_at' => now()]);
        Sale::factory()->create(['business_id' => $business->id, 'total' => 50000, 'closed_at' => now()->subDay()]);

        $data = $this->insight()->gatherData($business->id);

        $this->assertSame(100000.0, $data['sales_today_total']);
        $this->assertSame(100.0, $data['sales_today_vs_yesterday_pct']);
        $this->assertTrue($this->insight()->isWorthShowing($data));
    }

    public function test_counts_all_low_stock_products_not_just_the_top_three_shown_on_the_overview_card(): void
    {
        // Bug de legacy corregido al portar: contaba "productos por agotarse"
        // sobre la lista ya topada a 3 nombres de DailyOverviewInsight, en vez
        // del total real bajo el umbral. Con 4 productos bajos, la salud debe
        // reflejar los 4, no quedarse en 3.
        $business = Business::factory()->create(['feature_flags' => []]);

        for ($i = 0; $i < 4; $i++) {
            Product::factory()->create([
                'business_id' => $business->id,
                'is_active' => true,
                'track_stock' => true,
                'is_single_sale' => false,
                'stock' => 1,
                'low_stock_alert_threshold' => 5,
            ]);
        }

        $data = $this->insight()->gatherData($business->id);

        $this->assertSame('red', $data['health_level']);
        $this->assertStringContainsString('4 producto', $data['health_factor']);
    }

    public function test_does_not_flag_priority_for_a_low_ingredient_without_recent_consumption(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['ingredients' => true]]);
        Ingredient::factory()->create([
            'business_id' => $business->id, 'is_active' => true, 'stock' => 1, 'min_stock' => 10,
        ]);

        $data = $this->insight()->gatherData($business->id);

        $this->assertNull($data['priority']);
    }

    public function test_chooses_the_oldest_receivable_as_priority_when_30_days_or_older(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['receivables' => true]]);
        $receivable = Receivable::factory()->create([
            'business_id' => $business->id, 'customer_name' => 'Juan Perez', 'balance' => 20000,
        ]);
        $receivable->forceFill(['created_at' => now()->subDays(35)])->save();

        $data = $this->insight()->gatherData($business->id);

        $this->assertNotNull($data['priority']);
        $this->assertSame('receivable', $data['priority']['type']);
        $this->assertSame('Juan Perez', $data['priority']['name']);
    }

    public function test_chooses_the_oldest_payable_as_priority_when_30_days_or_older(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['inventory' => true]]);
        $supplier = Supplier::factory()->create(['business_id' => $business->id, 'name' => 'Distribuidora ABC']);
        $purchase = Purchase::factory()->credit()->create([
            'business_id' => $business->id, 'supplier_id' => $supplier->id,
            'purchased_at' => now()->subDays(40)->toDateString(),
        ]);
        $product = Product::factory()->create(['business_id' => $business->id]);
        $purchase->lines()->create([
            'product_id' => $product->id, 'quantity' => 1, 'unit_cost_cop' => 15000, 'line_total_cop' => 15000,
        ]);

        $data = $this->insight()->gatherData($business->id);

        $this->assertNotNull($data['priority']);
        $this->assertSame('payable', $data['priority']['type']);
        $this->assertSame('Distribuidora ABC', $data['priority']['name']);
    }

    public function test_a_fully_paid_purchase_is_not_reported_as_an_oldest_payable(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['inventory' => true]]);
        $purchase = Purchase::factory()->create([
            'business_id' => $business->id, 'purchased_at' => now()->subDays(40)->toDateString(),
        ]);
        $product = Product::factory()->create(['business_id' => $business->id]);
        $purchase->lines()->create([
            'product_id' => $product->id, 'quantity' => 1, 'unit_cost_cop' => 15000, 'line_total_cop' => 15000,
        ]);

        $data = $this->insight()->gatherData($business->id);

        $this->assertNull($data['priority']);
    }
}
