<?php

namespace Tests\Feature\Services\Ai\Insights;

use App\Models\Business;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Ai\Insights\DailyOverviewInsight;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DailyOverviewInsightTest extends TestCase
{
    use DatabaseTransactions;

    private function insight(): DailyOverviewInsight
    {
        return app(DailyOverviewInsight::class);
    }

    public function test_is_not_worth_showing_with_no_sales_history_or_low_stock(): void
    {
        $business = Business::factory()->create(['feature_flags' => []]);

        $data = $this->insight()->gatherData($business->id);

        $this->assertFalse($this->insight()->isWorthShowing($data));
        $this->assertSame([], $data['products_running_out']);
    }

    public function test_reports_todays_sales_total_and_count(): void
    {
        $business = Business::factory()->create(['feature_flags' => []]);
        Sale::factory()->create(['business_id' => $business->id, 'total' => 40000, 'closed_at' => now()]);
        Sale::factory()->create(['business_id' => $business->id, 'total' => 60000, 'closed_at' => now()]);

        $data = $this->insight()->gatherData($business->id);

        $this->assertSame(100000.0, $data['sales_today_total']);
        $this->assertSame(2, $data['sales_today_count']);
        $this->assertTrue($this->insight()->isWorthShowing($data));
    }

    public function test_lists_up_to_three_low_stock_product_names_and_flags_the_most_urgent_one(): void
    {
        $business = Business::factory()->create(['feature_flags' => []]);
        for ($i = 0; $i < 4; $i++) {
            Product::factory()->create([
                'business_id' => $business->id, 'is_active' => true, 'track_stock' => true,
                'is_single_sale' => false, 'stock' => 1, 'low_stock_alert_threshold' => 5,
            ]);
        }

        $data = $this->insight()->gatherData($business->id);

        $this->assertCount(3, $data['products_running_out']);
        // Sin movimiento de venta reciente, ninguno tiene coverage_days: no
        // hay "mas urgente" que reportar todavia.
        $this->assertNull($data['most_urgent_product']);
    }

    /**
     * Regresion: lowStockProducts() filtraba por kind === 'product', asi que
     * un item kind === 'product_variant' (ver LowStockAlertReport) quedaba
     * excluido en silencio de este resumen diario aunque estuviera bajo su
     * propio umbral.
     */
    public function test_includes_low_stock_variants_among_products_running_out(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['variants' => true]]);
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 0, 'is_active' => true, 'is_single_sale' => false]);
        $product->variants()->create(['business_id' => $business->id, 'sku' => 'CAM-S', 'price' => 45000, 'stock' => 1, 'low_stock_alert_threshold' => 5]);

        $data = $this->insight()->gatherData($business->id);

        $this->assertCount(1, $data['products_running_out']);
        $this->assertStringContainsString($product->name, $data['products_running_out'][0]);
    }

    public function test_rejects_generated_text_that_names_the_wrong_weekday(): void
    {
        $data = ['weekday' => 'lunes'];

        $this->assertFalse($this->insight()->isTextValid('Hoy jueves vas muy bien.', $data));
        $this->assertTrue($this->insight()->isTextValid('Hoy vas muy bien para un lunes.', $data));
    }
}
