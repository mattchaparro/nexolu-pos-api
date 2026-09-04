<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Product;
use App\Models\User;
use App\Services\BranchComparisonService;
use App\Services\BusinessOverviewService;
use App\Support\BranchContext;
use App\Support\ProductProfitBreakdown;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Reportes con dimension de sede.
 *
 * El riesgo que cubren estos tests no es que falte un filtro, sino que este
 * a medias: casi todos los reportes consultan via Eloquent y heredaron el
 * scope de sede solos, pero cuatro agregan con joins crudos sobre sale_items
 * y se lo saltaban. Ver el dia de UNA sede junto a la rotacion de productos
 * de TODAS es peor que no filtrar: son dos cifras del mismo tablero que no
 * cuadran, sin ninguna pista de por que.
 */
class BranchReportingTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        BranchContext::forget();

        parent::tearDown();
    }

    public function test_the_overview_reports_only_the_active_branch(): void
    {
        [$business, $main, $second, $user] = $this->scenario();
        $product = $this->productIn($business, $second, 10000, 40);

        $this->sellIn($main, $user, $product, 2);
        $this->sellIn($second, $user, $product, 3);

        $overview = app(BusinessOverviewService::class);
        $revenueOf = fn () => (float) $overview
            ->overview($business, (int) now()->year, (int) now()->month)['period']['summary']['revenue'];

        BranchContext::set($main);
        $this->assertSame(20000.0, $revenueOf());

        BranchContext::set($second);
        $this->assertSame(30000.0, $revenueOf());

        BranchContext::setAllBranches();
        $this->assertSame(50000.0, $revenueOf());
    }

    /**
     * El caso que motivo BranchFilter: esta consulta agrega con un join
     * crudo sobre sale_items, asi que el global scope del modelo Sale no la
     * alcanza.
     */
    public function test_the_profit_breakdown_respects_the_active_branch(): void
    {
        [$business, $main, $second, $user] = $this->scenario();
        $product = $this->productIn($business, $second, 10000, 40);

        $this->sellIn($main, $user, $product, 2);
        $this->sellIn($second, $user, $product, 5);

        $from = now()->startOfMonth();
        $to = now()->endOfDay();

        BranchContext::set($main);
        $this->assertSame(20000.0, ProductProfitBreakdown::forPeriod($business->id, $from, $to)['total_revenue']);

        BranchContext::set($second);
        $this->assertSame(50000.0, ProductProfitBreakdown::forPeriod($business->id, $from, $to)['total_revenue']);

        BranchContext::setAllBranches();
        $this->assertSame(70000.0, ProductProfitBreakdown::forPeriod($business->id, $from, $to)['total_revenue']);
    }

    public function test_the_comparison_breaks_down_revenue_and_expenses_by_branch(): void
    {
        [$business, $main, $second, $user] = $this->scenario();
        $product = $this->productIn($business, $second, 10000, 40);

        $this->sellIn($main, $user, $product, 3);
        $this->sellIn($second, $user, $product, 1);

        BranchContext::set($main);
        Expense::factory()->create(['business_id' => $business->id, 'value' => 5000, 'date' => now()->toDateString()]);
        BranchContext::forget();

        $result = app(BranchComparisonService::class)
            ->forPeriod($business->id, now()->startOfMonth(), now()->endOfDay());

        $rows = collect($result['branches'])->keyBy('branch_id');

        $this->assertSame(30000.0, $rows[$main->id]['revenue']);
        $this->assertSame(5000.0, $rows[$main->id]['expenses']);
        $this->assertSame(25000.0, $rows[$main->id]['net']);
        $this->assertSame(75.0, $rows[$main->id]['revenue_share_pct']);

        $this->assertSame(10000.0, $rows[$second->id]['revenue']);
        $this->assertSame(25.0, $rows[$second->id]['revenue_share_pct']);

        // El consolidado tiene que ser exactamente la suma de las partes, o
        // el dueño no puede confiar en ninguna de las dos cifras.
        $this->assertSame(40000.0, $result['totals']['revenue']);
        // Dos VENTAS (una en cada sede), no cuatro unidades: la de la
        // principal llevo 3 unidades en un solo ticket.
        $this->assertSame(2, $result['totals']['sales_count']);
        $this->assertSame(35000.0, $result['totals']['net']);
    }

    public function test_the_comparison_ignores_the_active_branch(): void
    {
        [$business, $main, $second, $user] = $this->scenario();
        $product = $this->productIn($business, $second, 10000, 40);
        $this->sellIn($main, $user, $product, 1);
        $this->sellIn($second, $user, $product, 1);

        // Un comparativo que solo viera la sede activa no compararia nada.
        BranchContext::set($main);
        $result = app(BranchComparisonService::class)
            ->forPeriod($business->id, now()->startOfMonth(), now()->endOfDay());

        $this->assertCount(2, $result['branches']);
        $this->assertSame(20000.0, $result['totals']['revenue']);
    }

    public function test_a_branch_without_movement_still_appears_with_zeros(): void
    {
        [$business, $main, $second, $user] = $this->scenario();
        $product = $this->productIn($business, $second, 10000, 40);
        $this->sellIn($main, $user, $product, 1);

        $result = app(BranchComparisonService::class)
            ->forPeriod($business->id, now()->startOfMonth(), now()->endOfDay());

        $quiet = collect($result['branches'])->firstWhere('branch_id', $second->id);

        // Una sede que no vendio nada tiene que salir en cero, no
        // desaparecer: "no aparece" se lee como un error del reporte.
        $this->assertSame(0, $quiet['sales_count']);
        $this->assertSame(0.0, $quiet['revenue']);
        $this->assertSame(0.0, $quiet['revenue_share_pct']);
    }

    public function test_only_an_admin_sees_the_comparison(): void
    {
        [$business, $main] = $this->scenario();
        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);
        $employee->assignRole('employee');
        $employee->branches()->attach([$main->id]);

        $this->actingAs($employee, 'sanctum')->getJson('/api/v1/reports/branches')->assertForbidden();
    }

    public function test_the_endpoint_returns_the_comparison_for_an_admin(): void
    {
        [$business, $main, $second, $user] = $this->scenario();
        $product = $this->productIn($business, $second, 10000, 40);
        $this->sellIn($main, $user, $product, 2);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/branches')
            ->assertOk()
            ->assertJsonCount(2, 'branches')
            ->assertJsonPath('totals.revenue', 20000);
    }

    private function productIn(Business $business, Branch $second, float $price, int $stock): Product
    {
        $product = Product::factory()->for($business)->create(['price' => $price, 'stock' => $stock]);
        BranchStock::add($business->id, $second->id, 'product_id', $product->id, $stock);

        return $product;
    }

    private function sellIn(Branch $branch, User $user, Product $product, int $quantity): void
    {
        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $branch->id)
            ->postJson('/api/v1/sales', [
                'payment_method' => 'cash',
                'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            ])->assertCreated();
    }

    /** @return array{0: Business, 1: Branch, 2: Branch, 3: User} */
    /**
     * El reporte de margenes mezclaba escalas: sus ventas SI se filtraban por
     * sede (BranchFilter sobre el join de sale_items) pero el stock de la
     * misma fila salia de la columna agregada del catalogo. El dueño leia
     * "vendi 3 en esta sede y me quedan 40" cuando en esa sede quedaba 1.
     */
    public function test_the_margins_report_uses_the_stock_of_the_active_branch(): void
    {
        [$business, $main, $second, $user] = $this->scenario();
        // El reporte de margenes exige inventory_advanced (o ingredients)
        // ademas del permiso - ver InventoryReportController::margins().
        $business->update(['feature_flags' => $business->feature_flags + ['inventory_advanced' => true]]);

        // Se crea en 0 y se reparte a mano: crearlo con saldo dispara el
        // sembrado automatico de bootHasBranchStock en la sede principal y el
        // reparto dejaria de ser el que dice el test.
        $product = Product::factory()->for($business)->create([
            'price' => 10000,
            'cost_price' => 6000,
            'stock' => 50,
            'track_stock' => true,
            'is_active' => true,
            'is_single_sale' => false,
        ]);
        BranchStock::query()->where('product_id', $product->id)->delete();
        BranchStock::add($business->id, $main->id, 'product_id', $product->id, 40);
        BranchStock::add($business->id, $second->id, 'product_id', $product->id, 10);

        // El contenedor se reusa entre requests del test, asi que la sede de
        // la llamada anterior sobreviviria; en produccion cada request
        // arranca limpio.
        $rowFor = function (string $header) use ($user): array {
            BranchContext::forget();

            return $this->actingAs($user, 'sanctum')
                ->withHeader('X-Branch-Id', $header)
                ->getJson('/api/v1/reports/inventory/margins')
                ->assertOk()
                ->json('margin_rows.0');
        };

        $this->assertSame(10, $rowFor((string) $second->id)['stock']);
        $this->assertSame(40, $rowFor((string) $main->id)['stock']);
        // El consolidado se pide explicitamente con 'all': omitir el header NO
        // significa "todas las sedes", cae en la principal (ver ResolveBranch).
        $this->assertSame(50, $rowFor('all')['stock']);
    }

    /** La utilidad potencial se calcula sobre ese mismo stock, no sobre el total. */
    public function test_the_margins_potential_profit_follows_the_branch_stock(): void
    {
        [$business, , $second, $user] = $this->scenario();
        $business->update(['feature_flags' => $business->feature_flags + ['inventory_advanced' => true]]);

        $product = Product::factory()->for($business)->create([
            'price' => 10000,
            'cost_price' => 6000,
            'stock' => 50,
            'track_stock' => true,
            'is_active' => true,
            'is_single_sale' => false,
        ]);
        BranchStock::query()->where('product_id', $product->id)->delete();
        BranchStock::add($business->id, $second->id, 'product_id', $product->id, 10);

        $row = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $second->id)
            ->getJson('/api/v1/reports/inventory/margins')
            ->assertOk()
            ->json('margin_rows.0');

        // 10 unidades x $4.000 de margen, no 50.
        $this->assertEquals(40000.0, $row['profit_total']);
    }

    private function scenario(): array
    {
        $business = Business::factory()->create([
            'feature_flags' => ['inventory' => true, 'multi_branch' => true, 'expenses' => true],
        ]);
        $main = Branch::factory()->for($business)->main()->create(['name' => 'Punto de fabrica']);
        $second = Branch::factory()->for($business)->create(['name' => 'Centro comercial']);

        $user = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $user->assignRole('admin');

        return [$business, $main, $second, $user];
    }
}
