<?php

namespace Tests\Feature\Console;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El cierre de dia automatizado (businesses:day-report) tiene que hacer las
 * dos cosas: dar verde en un dia limpio y ATRAPAR un descuadre real. Un
 * reporte que solo sabe decir OK no verifica nada - misma filosofia que los
 * tests de VerifyBusinessMigration.
 */
class BusinessDayReportTest extends TestCase
{
    use DatabaseTransactions;

    private function makeCleanSale(Business $business, User $user, float $total = 5000): Sale
    {
        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'closed',
            'payment_method' => 'cash',
            'is_non_revenue' => false,
            'is_credit' => false,
            'total' => $total,
            'closed_at' => now(),
        ]);

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'track_stock' => false,
            'price' => $total,
        ]);

        DB::table('sale_items')->insert([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $total,
            'subtotal' => $total,
            'discount_amount' => 0,
        ]);

        return $sale;
    }

    public function test_a_clean_day_reports_ok(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $this->makeCleanSale($business, $user);

        $this->artisan('businesses:day-report', ['business' => $business->id])
            ->expectsOutputToContain('D1 items=total')
            ->expectsOutputToContain('DIA CUADRADO')
            ->assertSuccessful();
    }

    public function test_detects_a_sale_whose_total_does_not_match_its_items(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $sale = $this->makeCleanSale($business, $user, 5000);

        // Total manipulado: los items suman 5000 pero la venta dice 9000.
        $sale->forceFill(['total' => 9000])->save();

        $this->artisan('businesses:day-report', ['business' => $business->id])
            ->expectsOutputToContain('DIA CON DESCUADRES')
            ->assertFailed();
    }

    public function test_detects_stock_moved_without_a_sale_backing_it(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $sale = $this->makeCleanSale($business, $user);
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 10]);

        // Movimiento tipo venta referenciando una venta del dia, pero por
        // MAS unidades de las que la venta tiene en items (doble descuento).
        // branch_id es NOT NULL desde multisede: el movimiento necesita sede.
        $branch = Branch::factory()->create(['business_id' => $business->id, 'is_main' => true]);
        DB::table('stock_movements')->insert([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'type' => 'sale',
            'quantity' => -3,
            'reference' => "Venta #{$sale->id}",
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('businesses:day-report', ['business' => $business->id])
            ->expectsOutputToContain('D4 stock=ventas')
            ->expectsOutputToContain('DIA CON DESCUADRES')
            ->assertFailed();
    }

    public function test_detects_negative_stock(): void
    {
        $business = Business::factory()->create();
        Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => -4]);

        $this->artisan('businesses:day-report', ['business' => $business->id])
            ->expectsOutputToContain('stock negativo')
            ->assertFailed();
    }
}
