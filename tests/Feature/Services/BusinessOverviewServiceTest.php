<?php

namespace Tests\Feature\Services;

use App\Models\Business;
use App\Models\Layaway;
use App\Models\LayawayPayment;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServiceOrder;
use App\Models\ServicePayment;
use App\Services\BusinessOverviewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Mi negocio": el reporte que el usuario pidio explicitamente que NO fuera
 * una copia de Resumen del dia - crecimiento vs ayer/semana pasada, patron
 * de horas, descuentos entregados, fiado pendiente, y rotacion de productos
 * con su impacto en el ingreso del mes.
 */
class BusinessOverviewServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function service(): BusinessOverviewService
    {
        return app(BusinessOverviewService::class);
    }

    private function closedSale(Business $business, float $total, \DateTimeInterface|string $closedAt, array $overrides = []): Sale
    {
        return Sale::factory()->create(array_merge([
            'business_id' => $business->id,
            'payment_method' => 'cash',
            'total' => $total,
            'status' => 'closed',
            'closed_at' => $closedAt,
        ], $overrides));
    }

    public function test_pulse_sums_the_three_plus_one_revenue_sources_for_today(): void
    {
        $business = Business::factory()->create();
        $this->closedSale($business, 20000, now());

        ServicePayment::factory()->create([
            'business_id' => $business->id,
            'service_order_id' => ServiceOrder::factory()->create(['business_id' => $business->id])->id,
            'amount' => 5000,
            'created_at' => now(),
        ]);

        LayawayPayment::factory()->create([
            'business_id' => $business->id,
            'layaway_id' => Layaway::factory()->create(['business_id' => $business->id])->id,
            'amount' => 3000,
            'created_at' => now(),
        ]);

        Receivable::factory()->paid()->create([
            'business_id' => $business->id,
            'amount' => 7000,
            'paid_at' => now(),
        ]);

        $pulse = $this->service()->overview($business, (int) now()->year, (int) now()->month)['pulse'];

        $this->assertSame(35000.0, $pulse['today']['revenue']);
        $this->assertSame(1, $pulse['today']['sales_count']);
    }

    public function test_today_vs_yesterday_growth_percentage(): void
    {
        $business = Business::factory()->create();
        $this->closedSale($business, 20000, now());
        $this->closedSale($business, 10000, now()->subDay());

        $pulse = $this->service()->overview($business, (int) now()->year, (int) now()->month)['pulse'];

        $this->assertSame(10000.0, $pulse['yesterday']['revenue']);
        $this->assertSame(100.0, $pulse['today_vs_yesterday_pct']);
    }

    public function test_growth_percentage_is_null_when_the_previous_period_had_no_revenue(): void
    {
        $business = Business::factory()->create();
        $this->closedSale($business, 20000, now());

        $pulse = $this->service()->overview($business, (int) now()->year, (int) now()->month)['pulse'];

        $this->assertNull($pulse['today_vs_yesterday_pct']);
    }

    public function test_this_week_vs_last_week_uses_rolling_seven_day_windows(): void
    {
        $business = Business::factory()->create();
        $this->closedSale($business, 10000, now()); // esta semana (dia 0)
        $this->closedSale($business, 10000, now()->subDays(6)); // esta semana (borde, dia -6)
        $this->closedSale($business, 5000, now()->subDays(7)); // semana pasada (borde, dia -7)
        $this->closedSale($business, 5000, now()->subDays(13)); // semana pasada (borde, dia -13)
        $this->closedSale($business, 999999, now()->subDays(14)); // fuera de ambas ventanas

        $pulse = $this->service()->overview($business, (int) now()->year, (int) now()->month)['pulse'];

        $this->assertSame(20000.0, $pulse['this_week']['revenue']);
        $this->assertSame(10000.0, $pulse['last_week']['revenue']);
    }

    public function test_trend_fills_days_without_sales_with_zero(): void
    {
        $business = Business::factory()->create();
        $this->closedSale($business, 15000, now());

        $trend = $this->service()->overview($business, (int) now()->year, (int) now()->month)['trend'];

        $this->assertCount(30, $trend['days']);
        $today = collect($trend['days'])->firstWhere('date', now()->toDateString());
        $this->assertSame(15000.0, $today['revenue']);
        $yesterday = collect($trend['days'])->firstWhere('date', now()->subDay()->toDateString());
        $this->assertSame(0.0, $yesterday['revenue']);
    }

    /** El caso de negocio real: identificar la hora mas fuerte y la mas floja para planear turnos. */
    public function test_heatmap_identifies_the_peak_and_slow_hours(): void
    {
        Carbon::setTestNow(now()->startOfDay()->addHours(14));
        $business = Business::factory()->create();
        $this->closedSale($business, 50000, now()); // hora pico
        $this->closedSale($business, 5000, now()->subHours(2)); // hora floja

        $heatmap = $this->service()->overview($business, (int) now()->year, (int) now()->month)['heatmap'];

        $this->assertCount(168, $heatmap['cells']);
        $this->assertSame(14, $heatmap['peak']['hour']);
        $this->assertSame(50000.0, $heatmap['peak']['revenue']);
        $this->assertSame(12, $heatmap['slow']['hour']);
    }

    public function test_heatmap_peak_and_slow_are_null_without_any_sales(): void
    {
        $business = Business::factory()->create();

        $heatmap = $this->service()->overview($business, (int) now()->year, (int) now()->month)['heatmap'];

        $this->assertNull($heatmap['peak']);
        $this->assertNull($heatmap['slow']);
    }

    /**
     * Bug que se encontro y se corrigio escribiendo este mismo servicio: sumar
     * cart_discount_amount con un join a sale_items lo repetiria una vez por
     * item. Dos ventas con descuentos de carrito distintos deben sumar
     * completo, no perder ninguno.
     */
    public function test_discounts_total_combines_item_and_cart_discounts_without_duplicating(): void
    {
        $business = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $business->id]);

        $sale = $this->closedSale($business, 50000, now(), ['cart_discount_amount' => 2000]);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 2, 'discount_amount' => 500]);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 1, 'discount_amount' => 300]);

        $sale2 = $this->closedSale($business, 30000, now(), ['cart_discount_amount' => 2000]); // mismo monto de descuento de carrito a proposito
        SaleItem::factory()->create(['sale_id' => $sale2->id, 'product_id' => $product->id, 'quantity' => 1]);

        $discounts = $this->service()->overview($business, (int) now()->year, (int) now()->month)['period']['discounts'];

        // item: 500+300=800, carrito: 2000+2000=4000 (NO 2000 si se hubiera deduplicado por error)
        $this->assertSame(4800.0, $discounts['total']);
    }

    public function test_receivables_outstanding_reflects_only_pending_balances(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['receivables' => true]]);
        Receivable::factory()->create(['business_id' => $business->id, 'status' => 'pending', 'amount' => 10000, 'balance' => 10000]);
        Receivable::factory()->create(['business_id' => $business->id, 'status' => 'pending', 'amount' => 5000, 'balance' => 5000]);
        Receivable::factory()->paid()->create(['business_id' => $business->id, 'amount' => 8000]);

        $receivables = $this->service()->overview($business, (int) now()->year, (int) now()->month)['period']['receivables'];

        $this->assertSame(15000.0, $receivables['outstanding_total']);
        $this->assertSame(2, $receivables['outstanding_count']);
    }

    public function test_receivables_section_is_null_when_the_feature_is_disabled(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['receivables' => false]]);

        $period = $this->service()->overview($business, (int) now()->year, (int) now()->month)['period'];

        $this->assertNull($period['receivables']);
        $this->assertFalse($this->service()->overview($business, (int) now()->year, (int) now()->month)['channels_enabled']['receivables']);
    }

    public function test_top_and_bottom_products_report_their_share_of_the_months_revenue(): void
    {
        $business = Business::factory()->create();
        $popular = Product::factory()->create(['business_id' => $business->id, 'name' => 'Popular']);
        $slow = Product::factory()->create(['business_id' => $business->id, 'name' => 'Lento']);

        $sale = $this->closedSale($business, 90000, now());
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $popular->id, 'quantity' => 10, 'subtotal' => 80000]);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $slow->id, 'quantity' => 1, 'subtotal' => 10000]);

        $period = $this->service()->overview($business, (int) now()->year, (int) now()->month)['period'];

        $this->assertSame('Popular', $period['top_products'][0]['name']);
        $this->assertSame(88.9, $period['top_products'][0]['pct_of_revenue']);
        $this->assertSame('Lento', $period['bottom_products'][0]['name']);
        $this->assertSame(11.1, $period['bottom_products'][0]['pct_of_revenue']);
    }

    public function test_top_services_is_null_when_the_feature_is_disabled(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['services' => false]]);

        $period = $this->service()->overview($business, (int) now()->year, (int) now()->month)['period'];

        $this->assertNull($period['top_services']);
    }

    public function test_month_summary_computes_growth_against_the_previous_calendar_month(): void
    {
        $business = Business::factory()->create();
        $thisMonth = now()->startOfMonth()->addDays(2);
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(2);

        $this->closedSale($business, 20000, $thisMonth);
        $this->closedSale($business, 10000, $lastMonth);

        $summary = $this->service()->overview($business, (int) $thisMonth->year, (int) $thisMonth->month)['period']['summary'];

        $this->assertSame(20000.0, $summary['revenue']);
        $this->assertSame(100.0, $summary['revenue_change_pct']);
    }

    /**
     * El margen expone la rentabilidad real del negocio (no solo cuanto se
     * vendio) - se gatea aparte de reports.sales, con su propio flag que pasa
     * el controlador segun accounting.manage. Sin el flag, la seccion ni se
     * calcula.
     */
    public function test_profit_section_is_null_without_the_permission_flag(): void
    {
        $business = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $business->id]);
        $sale = $this->closedSale($business, 50000, now());
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_cost_at_sale' => 1000]);

        $period = $this->service()->overview($business, (int) now()->year, (int) now()->month)['period'];

        $this->assertNull($period['profit']);
    }

    public function test_profit_section_reports_overall_margin_and_top_products_when_permitted(): void
    {
        $business = Business::factory()->create();
        $bestSeller = Product::factory()->create(['business_id' => $business->id, 'name' => 'Rentable']);
        $lowMargin = Product::factory()->create(['business_id' => $business->id, 'name' => 'Justo']);

        $bestSeller->update(['sku' => 'PROD-RENT']);

        $sale = $this->closedSale($business, 90000, now());
        // Rentable: 2 x $10000 (subtotal 20000), costo 1000 c/u -> utilidad 18000
        SaleItem::factory()->create([
            'sale_id' => $sale->id, 'product_id' => $bestSeller->id,
            'quantity' => 2, 'unit_price' => 10000, 'subtotal' => 20000, 'discount_amount' => 0, 'unit_cost_at_sale' => 1000,
        ]);
        // Justo: 1 x $10000, costo 9000 -> utilidad 1000
        SaleItem::factory()->create([
            'sale_id' => $sale->id, 'product_id' => $lowMargin->id,
            'quantity' => 1, 'unit_price' => 10000, 'subtotal' => 10000, 'discount_amount' => 0, 'unit_cost_at_sale' => 9000,
        ]);

        $profit = $this->service()->overview($business, (int) now()->year, (int) now()->month, canViewProfit: true)['period']['profit'];

        $this->assertSame(30000.0, $profit['revenue']);
        $this->assertSame(11000.0, $profit['cost']);
        $this->assertSame(19000.0, $profit['profit']);
        $this->assertSame(63.3, $profit['margin_pct']);
        $this->assertCount(2, $profit['top_products']);
        $this->assertSame('Rentable', $profit['top_products'][0]['name']);
        $this->assertSame('PROD-RENT', $profit['top_products'][0]['sku']);
        $this->assertSame(18000.0, $profit['top_products'][0]['profit']);
        $this->assertSame(90.0, $profit['top_products'][0]['margin_pct']);
        $this->assertSame('Justo', $profit['top_products'][1]['name']);
    }

    public function test_profit_excludes_products_without_a_configured_cost(): void
    {
        $business = Business::factory()->create();
        $costed = Product::factory()->create(['business_id' => $business->id, 'name' => 'Con costo']);
        $uncosted = Product::factory()->create(['business_id' => $business->id, 'name' => 'Sin costo', 'cost_price' => 0]);

        $sale = $this->closedSale($business, 30000, now());
        SaleItem::factory()->create([
            'sale_id' => $sale->id, 'product_id' => $costed->id,
            'quantity' => 1, 'subtotal' => 10000, 'discount_amount' => 0, 'unit_cost_at_sale' => 4000,
        ]);
        SaleItem::factory()->create([
            'sale_id' => $sale->id, 'product_id' => $uncosted->id,
            'quantity' => 1, 'subtotal' => 20000, 'discount_amount' => 0, 'unit_cost_at_sale' => 0,
        ]);

        $profit = $this->service()->overview($business, (int) now()->year, (int) now()->month, canViewProfit: true)['period']['profit'];

        $this->assertCount(1, $profit['top_products']);
        $this->assertSame('Con costo', $profit['top_products'][0]['name']);
        $this->assertSame(10000.0, $profit['revenue']); // no incluye los $20000 sin costo
        $this->assertSame(1, $profit['uncosted_products_count']);
        $this->assertSame(20000.0, $profit['uncosted_revenue']);
    }

    public function test_profit_excludes_credit_sales_not_yet_collected(): void
    {
        $business = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $business->id]);
        $creditSale = $this->closedSale($business, 50000, now(), ['is_credit' => true]);
        SaleItem::factory()->create([
            'sale_id' => $creditSale->id, 'product_id' => $product->id,
            'quantity' => 1, 'subtotal' => 50000, 'discount_amount' => 0, 'unit_cost_at_sale' => 10000,
        ]);

        $profit = $this->service()->overview($business, (int) now()->year, (int) now()->month, canViewProfit: true)['period']['profit'];

        $this->assertSame(0.0, $profit['revenue']);
        $this->assertCount(0, $profit['top_products']);
    }

    public function test_profit_top_products_caps_at_ten(): void
    {
        $business = Business::factory()->create();
        $sale = $this->closedSale($business, 999999, now());
        foreach (range(1, 12) as $i) {
            $product = Product::factory()->create(['business_id' => $business->id, 'name' => "Producto {$i}"]);
            SaleItem::factory()->create([
                'sale_id' => $sale->id, 'product_id' => $product->id,
                'quantity' => 1, 'subtotal' => 1000 * $i, 'discount_amount' => 0, 'unit_cost_at_sale' => 100,
            ]);
        }

        $profit = $this->service()->overview($business, (int) now()->year, (int) now()->month, canViewProfit: true)['period']['profit'];

        $this->assertCount(10, $profit['top_products']);
        $this->assertSame('Producto 12', $profit['top_products'][0]['name']); // el de mayor utilidad primero
    }
}
