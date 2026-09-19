<?php

namespace Tests\Feature\Console;

use App\Models\Business;
use App\Models\Expense;
use App\Models\LayawayPayment;
use App\Models\PurchasePayment;
use App\Models\Sale;
use App\Models\SalePartialPayment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyNormalizePaymentMethodsTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        // No dejar el ambiente "local" filtrado a otros tests de la suite.
        app()->detectEnvironment(fn () => 'testing');

        parent::tearDown();
    }

    public function test_it_refuses_to_run_outside_local_and_staging_environments(): void
    {
        $business = Business::factory()->create();
        $expense = Expense::factory()->create(['business_id' => $business->id, 'payment_method' => 'Efectivo']);

        $this->artisan('legacy:normalize-payment-methods')->assertFailed();

        $this->assertSame('Efectivo', $expense->fresh()->payment_method);
    }

    public function test_it_refuses_to_run_against_production(): void
    {
        app()->instance('env', 'production');

        $business = Business::factory()->create();
        $expense = Expense::factory()->create(['business_id' => $business->id, 'payment_method' => 'Efectivo']);

        $this->artisan('legacy:normalize-payment-methods')->assertFailed();

        $this->assertSame('Efectivo', $expense->fresh()->payment_method);
    }

    public function test_it_runs_against_staging_same_as_local(): void
    {
        app()->instance('env', 'staging');

        $business = Business::factory()->create();
        $expense = Expense::factory()->create(['business_id' => $business->id, 'payment_method' => 'Efectivo']);

        $this->artisan('legacy:normalize-payment-methods')
            ->expectsOutputToContain('expenses: 1 filas cambiaron.')
            ->assertSuccessful();

        $this->assertSame('cash', $expense->fresh()->payment_method);
    }

    public function test_dry_run_reports_pending_changes_without_writing(): void
    {
        app()->instance('env', 'local');

        $business = Business::factory()->create();
        $expense = Expense::factory()->create(['business_id' => $business->id, 'payment_method' => 'Efectivo']);

        $this->artisan('legacy:normalize-payment-methods', ['--dry-run' => true])
            ->expectsOutputToContain('expenses: 1 filas cambiarian.')
            ->assertSuccessful();

        $this->assertSame('Efectivo', $expense->fresh()->payment_method);
    }

    public function test_it_normalizes_a_capitalized_label_to_the_businesss_configured_id(): void
    {
        app()->instance('env', 'local');

        // Business::DEFAULT_PAYMENT_METHODS es ['cash','transfer','credit'] -
        // 'Efectivo' (label capitalizado de legacy) debe resolver a 'cash'
        // via el alias cash<->efectivo de Business::normalizePaymentMethodId().
        $business = Business::factory()->create();
        $expense = Expense::factory()->create(['business_id' => $business->id, 'payment_method' => 'Efectivo']);

        $this->artisan('legacy:normalize-payment-methods')
            ->expectsOutputToContain('expenses: 1 filas cambiaron.')
            ->assertSuccessful();

        $this->assertSame('cash', $expense->fresh()->payment_method);
    }

    public function test_it_leaves_already_normalized_rows_untouched(): void
    {
        app()->instance('env', 'local');

        $business = Business::factory()->create();
        $expense = Expense::factory()->create(['business_id' => $business->id, 'payment_method' => 'cash']);

        $this->artisan('legacy:normalize-payment-methods')
            ->expectsOutputToContain('expenses: 0 filas cambiaron.')
            ->assertSuccessful();

        $this->assertSame('cash', $expense->fresh()->payment_method);
    }

    public function test_it_runs_against_production_when_scoped_to_a_single_business(): void
    {
        app()->instance('env', 'production');

        $business = Business::factory()->create();
        $expense = Expense::factory()->create(['business_id' => $business->id, 'payment_method' => 'Efectivo']);

        $this->artisan('legacy:normalize-payment-methods', ['--business' => $business->id])
            ->expectsOutputToContain('expenses: 1 filas cambiaron.')
            ->assertSuccessful();

        $this->assertSame('cash', $expense->fresh()->payment_method);
    }

    public function test_business_scope_does_not_touch_other_businesses_rows(): void
    {
        app()->instance('env', 'local');

        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $expenseA = Expense::factory()->create(['business_id' => $businessA->id, 'payment_method' => 'Efectivo']);
        $expenseB = Expense::factory()->create(['business_id' => $businessB->id, 'payment_method' => 'Efectivo']);

        $this->artisan('legacy:normalize-payment-methods', ['--business' => $businessA->id])
            ->expectsOutputToContain('expenses: 1 filas cambiaron.')
            ->assertSuccessful();

        $this->assertSame('cash', $expenseA->fresh()->payment_method);
        $this->assertSame('Efectivo', $expenseB->fresh()->payment_method, 'no deberia haber tocado un negocio fuera del --business pedido');
    }

    public function test_rows_belonging_to_a_deleted_business_are_skipped_safely(): void
    {
        app()->instance('env', 'local');

        $business = Business::factory()->create();
        $expense = Expense::factory()->create(['business_id' => $business->id, 'payment_method' => 'Efectivo']);
        $business->delete();

        $this->artisan('legacy:normalize-payment-methods')
            ->expectsOutputToContain('expenses: 0 filas cambiaron.')
            ->assertSuccessful();

        $this->assertSame('Efectivo', $expense->fresh()->payment_method);
    }

    /**
     * El hueco que causo el bug de produccion del 2026-09-18: los abonos de
     * cuentas abiertas nunca se normalizaban (la tabla no estaba en la lista
     * del comando, ni en la del `payment-methods:normalize` del legacy). Como
     * `sale_partial_payments` no tiene business_id propio, se llega por el
     * join a `sales` - mismo caso que `sale_payment_splits`.
     */
    public function test_it_normalizes_partial_payments_reached_through_their_sale(): void
    {
        app()->instance('env', 'local');

        $business = Business::factory()->create();
        $sale = Sale::factory()->create(['business_id' => $business->id, 'status' => 'open']);
        $abono = SalePartialPayment::factory()->create(['sale_id' => $sale->id, 'payment_method' => 'transferencia']);

        $this->artisan('legacy:normalize-payment-methods')
            ->expectsOutputToContain('sale_partial_payments: 1 filas cambiaron.')
            ->assertSuccessful();

        $this->assertSame('transfer', $abono->fresh()->payment_method);
    }

    /**
     * Las otras dos tablas que tampoco estaban. A diferencia de los abonos,
     * estas SI tienen defensa al escribir (sus modelos usan el trait
     * NormalizesPaymentMethod, que necesita un business_id propio), asi que
     * una fila con vocabulario viejo solo puede entrar por fuera de Eloquent:
     * el import de la migracion, o el monolito legacy escribiendo en la base
     * compartida. Por eso el test las inserta con el modelo y las ensucia
     * despues por query cruda - es como llegan de verdad.
     */
    public function test_it_normalizes_layaway_and_purchase_payments(): void
    {
        app()->instance('env', 'local');

        $business = Business::factory()->create();
        $layaway = LayawayPayment::factory()->create(['business_id' => $business->id]);
        $purchase = PurchasePayment::factory()->create(['business_id' => $business->id]);
        DB::table('layaway_payments')->where('id', $layaway->id)->update(['payment_method' => 'efectivo']);
        DB::table('purchase_payments')->where('id', $purchase->id)->update(['payment_method' => 'transferencia']);

        $this->artisan('legacy:normalize-payment-methods')
            ->expectsOutputToContain('layaway_payments: 1 filas cambiaron.')
            ->expectsOutputToContain('purchase_payments: 1 filas cambiaron.')
            ->assertSuccessful();

        $this->assertSame('cash', $layaway->fresh()->payment_method);
        $this->assertSame('transfer', $purchase->fresh()->payment_method);
    }

    /**
     * El --business tiene que seguir acotando tambien por el join, no solo
     * por las tablas con business_id propio.
     */
    public function test_business_scope_also_applies_to_partial_payments(): void
    {
        app()->instance('env', 'local');

        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $saleA = Sale::factory()->create(['business_id' => $businessA->id, 'status' => 'open']);
        $saleB = Sale::factory()->create(['business_id' => $businessB->id, 'status' => 'open']);
        $abonoA = SalePartialPayment::factory()->create(['sale_id' => $saleA->id, 'payment_method' => 'transferencia']);
        $abonoB = SalePartialPayment::factory()->create(['sale_id' => $saleB->id, 'payment_method' => 'transferencia']);

        $this->artisan('legacy:normalize-payment-methods', ['--business' => $businessA->id])
            ->expectsOutputToContain('sale_partial_payments: 1 filas cambiaron.')
            ->assertSuccessful();

        $this->assertSame('transfer', $abonoA->fresh()->payment_method);
        $this->assertSame('transferencia', $abonoB->fresh()->payment_method, 'no deberia haber tocado un negocio fuera del --business pedido');
    }
}
