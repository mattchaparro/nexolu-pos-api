<?php

namespace Tests\Feature\Console;

use App\Models\Expense;
use App\Models\FixedExpenseTemplate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpensesRegisterScheduledTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registers_an_expense_for_a_template_matching_the_day(): void
    {
        $template = FixedExpenseTemplate::factory()->create(['day_of_month' => 15, 'amount' => 300000]);

        $this->artisan('expenses:register-scheduled', ['--date' => '2026-08-15'])->assertSuccessful();

        $this->assertDatabaseHas('expenses', [
            'fixed_expense_template_id' => $template->id,
            'description' => $template->name,
            'value' => '300000.00',
            'date' => '2026-08-15',
        ]);
    }

    public function test_does_not_register_a_template_whose_day_does_not_match(): void
    {
        FixedExpenseTemplate::factory()->create(['day_of_month' => 15]);

        $this->artisan('expenses:register-scheduled', ['--date' => '2026-08-16'])->assertSuccessful();

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_does_not_register_an_inactive_template(): void
    {
        FixedExpenseTemplate::factory()->create(['day_of_month' => 15, 'active' => false]);

        $this->artisan('expenses:register-scheduled', ['--date' => '2026-08-15'])->assertSuccessful();

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_does_not_register_a_template_without_an_amount(): void
    {
        FixedExpenseTemplate::factory()->create(['day_of_month' => 15, 'amount' => null]);

        $this->artisan('expenses:register-scheduled', ['--date' => '2026-08-15'])->assertSuccessful();

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_is_idempotent_within_the_same_month(): void
    {
        $template = FixedExpenseTemplate::factory()->create(['day_of_month' => 15]);
        Expense::withoutGlobalScopes()->create([
            'business_id' => $template->business_id,
            'date' => '2026-08-10',
            'description' => 'ya registrado',
            'value' => 1000,
            'fixed_expense_template_id' => $template->id,
        ]);

        $this->artisan('expenses:register-scheduled', ['--date' => '2026-08-15'])->assertSuccessful();

        $this->assertDatabaseCount('expenses', 1);
    }

    public function test_registers_again_the_following_month(): void
    {
        $template = FixedExpenseTemplate::factory()->create(['day_of_month' => 15]);
        Expense::withoutGlobalScopes()->create([
            'business_id' => $template->business_id,
            'date' => '2026-07-15',
            'description' => 'julio',
            'value' => 1000,
            'fixed_expense_template_id' => $template->id,
        ]);

        $this->artisan('expenses:register-scheduled', ['--date' => '2026-08-15'])->assertSuccessful();

        $this->assertDatabaseCount('expenses', 2);
    }

    public function test_defaults_to_today_without_the_date_option(): void
    {
        $template = FixedExpenseTemplate::factory()->create(['day_of_month' => (int) now()->day]);

        $this->artisan('expenses:register-scheduled')->assertSuccessful();

        $this->assertDatabaseHas('expenses', ['fixed_expense_template_id' => $template->id]);
    }
}
