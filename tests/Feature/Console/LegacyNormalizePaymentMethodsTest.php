<?php

namespace Tests\Feature\Console;

use App\Models\Business;
use App\Models\Expense;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
