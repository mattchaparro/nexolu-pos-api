<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\CashShift;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La operacion del dia a dia, ya separada por sede: vender, facturar, abrir
 * caja y registrar gastos en un local no puede mezclarse con lo del otro.
 *
 * El fiado es la excepcion deliberada y por eso tiene su propio test: la
 * deuda es con el negocio, no con el local donde se origino.
 */
class BranchOperationTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        BranchContext::forget();

        parent::tearDown();
    }

    public function test_each_branch_has_its_own_invoice_series_and_prefix(): void
    {
        [$business, $main, $second, $user] = $this->scenario();
        $main->update(['invoice_prefix' => 'FAB']);
        $second->update(['invoice_prefix' => 'CC']);
        // El alta con stock siembra la principal; la segunda sede necesita
        // el suyo o no puede vender (la validacion de stock ya es por sede).
        $product = Product::factory()->for($business)->create(['price' => 10000, 'stock' => 50]);
        BranchStock::add($business->id, $second->id, 'product_id', $product->id, 50);

        $sell = fn (Branch $branch) => $this->actingAs($user, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $branch->id)
            ->postJson('/api/v1/sales', [
                'payment_method' => 'cash',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertCreated()->json('invoice_number');

        $this->assertSame('FAB-000001', $sell($main));
        $this->assertSame('FAB-000002', $sell($main));

        // La segunda sede arranca su propia serie en 1, no continua la otra.
        $this->assertSame('CC-000001', $sell($second));
        $this->assertSame('CC-000002', $sell($second));
        $this->assertSame('FAB-000003', $sell($main));
    }

    public function test_a_branch_without_its_own_prefix_uses_the_one_of_the_business(): void
    {
        [$business, $main, , $user] = $this->scenario();
        $business->update(['invoice_prefix' => 'NEX']);
        $product = Product::factory()->for($business)->create(['price' => 5000, 'stock' => 10]);

        $invoice = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $main->id)
            ->postJson('/api/v1/sales', [
                'payment_method' => 'cash',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertCreated()->json('invoice_number');

        $this->assertSame('NEX-000001', $invoice);
    }

    public function test_sales_are_only_visible_from_the_branch_that_made_them(): void
    {
        [$business, $main, $second, $user] = $this->scenario();
        $product = Product::factory()->for($business)->create(['price' => 1000, 'stock' => 50]);
        BranchStock::add($business->id, $second->id, 'product_id', $product->id, 50);

        $this->sellIn($main, $user, $product);
        $this->sellIn($second, $user, $product);
        $this->sellIn($second, $user, $product);

        BranchContext::set($main);
        $this->assertSame(1, Sale::count());

        BranchContext::set($second);
        $this->assertSame(2, Sale::count());

        BranchContext::setAllBranches();
        $this->assertSame(3, Sale::count(), 'El consolidado ve las tres.');
    }

    public function test_a_sale_deducts_stock_from_the_branch_it_was_sold_in(): void
    {
        [$business, $main, $second, $user] = $this->scenario();
        $product = Product::factory()->for($business)->create(['price' => 1000, 'stock' => 20]);

        // Reparte: 20 en la principal, y le pasamos 5 a la segunda.
        BranchStock::add($business->id, $second->id, 'product_id', $product->id, 5);

        $this->sellIn($second, $user, $product, 2);

        $this->assertSame(20.0, BranchStock::quantity($main->id, 'product_id', $product->id));
        $this->assertSame(3.0, BranchStock::quantity($second->id, 'product_id', $product->id));
    }

    public function test_cash_shifts_and_expenses_belong_to_their_branch(): void
    {
        [$business, $main, $second, $user] = $this->scenario();

        BranchContext::set($main);
        CashShift::factory()->create(['business_id' => $business->id, 'user_id' => $user->id]);
        Expense::factory()->create(['business_id' => $business->id]);

        BranchContext::set($second);
        Expense::factory()->create(['business_id' => $business->id]);

        $this->assertSame(0, CashShift::count(), 'La caja de la principal no es la de esta sede.');
        $this->assertSame(1, Expense::count());

        BranchContext::set($main);
        $this->assertSame(1, CashShift::count());
        $this->assertSame(1, Expense::count());
    }

    /**
     * El fiado se registra donde se origino pero NO se filtra por sede: si el
     * cliente compro fiado en la fabrica y va a abonar al centro comercial,
     * esa caja tiene que encontrar la deuda.
     */
    public function test_credit_is_recorded_by_branch_but_collectable_from_any_of_them(): void
    {
        [$business, $main, $second, $user] = $this->scenario();

        BranchContext::set($main);
        $receivable = Receivable::factory()->create(['business_id' => $business->id]);

        $this->assertSame($main->id, (int) $receivable->fresh()->branch_id);

        BranchContext::set($second);
        $this->assertTrue(
            Receivable::whereKey($receivable->id)->exists(),
            'La deuda es con el negocio, no con el local.'
        );
    }

    /**
     * Los comandos y jobs no tienen sede activa. Antes de que la fila
     * aterrizara sola en la principal, un gasto programado por cron quedaba
     * con branch_id NULL y desaparecia de la pantalla de gastos.
     */
    public function test_a_row_created_without_branch_context_lands_on_the_main_branch(): void
    {
        [$business, $main] = $this->scenario();

        BranchContext::forget();
        $expense = Expense::factory()->create(['business_id' => $business->id]);

        $this->assertSame($main->id, (int) $expense->fresh()->branch_id);
    }

    private function sellIn(Branch $branch, User $user, Product $product, int $quantity = 1): void
    {
        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $branch->id)
            ->postJson('/api/v1/sales', [
                'payment_method' => 'cash',
                'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            ])->assertCreated();
    }

    /** @return array{0: Business, 1: Branch, 2: Branch, 3: User} */
    private function scenario(): array
    {
        $business = Business::factory()->create([
            'feature_flags' => ['inventory' => true, 'multi_branch' => true, 'expenses' => true],
        ]);
        $main = Branch::factory()->for($business)->main()->create();
        $second = Branch::factory()->for($business)->create();

        $user = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $user->assignRole('admin');

        return [$business, $main, $second, $user];
    }
}
