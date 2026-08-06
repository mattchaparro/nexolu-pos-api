<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\ExpenseType;
use App\Models\FixedExpenseTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FixedExpenseTemplateTest extends TestCase
{
    use DatabaseTransactions;

    private function businessAndAdmin(): array
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        return [$business, $user];
    }

    public function test_user_can_list_their_business_templates(): void
    {
        [$business, $user] = $this->businessAndAdmin();
        $otherBusiness = Business::factory()->create();

        FixedExpenseTemplate::factory()->count(2)->create(['business_id' => $business->id]);
        FixedExpenseTemplate::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/fixed-expense-templates')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_user_can_create_a_template(): void
    {
        [$business, $user] = $this->businessAndAdmin();
        $type = ExpenseType::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/fixed-expense-templates', [
            'name' => 'Arriendo local',
            'amount' => 1500000,
            'expense_type_id' => $type->id,
            'day_of_month' => 5,
            'scope' => 'administrativo',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Arriendo local')
            ->assertJsonPath('amount', '1500000.00')
            ->assertJsonPath('active', true)
            ->assertJsonPath('registered_this_month', false);

        $this->assertDatabaseHas('fixed_expense_templates', [
            'business_id' => $business->id,
            'name' => 'Arriendo local',
        ]);
    }

    public function test_day_of_month_must_be_between_1_and_28(): void
    {
        [$business, $user] = $this->businessAndAdmin();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/fixed-expense-templates', [
            'name' => 'Nomina',
            'day_of_month' => 30,
        ])->assertStatus(422)->assertJsonValidationErrors('day_of_month');
    }

    public function test_user_can_update_a_template(): void
    {
        [$business, $user] = $this->businessAndAdmin();
        $template = FixedExpenseTemplate::factory()->create(['business_id' => $business->id, 'active' => true]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/fixed-expense-templates/{$template->id}", [
            'active' => false,
        ])->assertOk()->assertJsonPath('active', false);

        $this->assertFalse($template->fresh()->active);
    }

    public function test_user_cannot_update_a_template_from_another_business(): void
    {
        [$business, $user] = $this->businessAndAdmin();
        $otherBusiness = Business::factory()->create();
        $template = FixedExpenseTemplate::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/fixed-expense-templates/{$template->id}", ['name' => 'Hackeado'])
            ->assertNotFound();
    }

    public function test_user_can_delete_a_template(): void
    {
        [$business, $user] = $this->businessAndAdmin();
        $template = FixedExpenseTemplate::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/fixed-expense-templates/{$template->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('fixed_expense_templates', ['id' => $template->id]);
    }

    public function test_register_now_creates_an_expense_for_the_requested_month(): void
    {
        [$business, $user] = $this->businessAndAdmin();
        $template = FixedExpenseTemplate::factory()->create([
            'business_id' => $business->id, 'name' => 'Arriendo', 'amount' => 800000, 'day_of_month' => 10,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/fixed-expense-templates/{$template->id}/register-now",
            ['year' => 2026, 'month' => 3]
        );

        $response->assertCreated()
            ->assertJsonPath('description', 'Arriendo')
            ->assertJsonPath('value', '800000.00')
            ->assertJsonPath('date', '2026-03-10');

        $this->assertDatabaseHas('expenses', [
            'business_id' => $business->id,
            'fixed_expense_template_id' => $template->id,
            'value' => 800000,
        ]);
    }

    public function test_register_now_is_idempotent_per_month(): void
    {
        [$business, $user] = $this->businessAndAdmin();
        $template = FixedExpenseTemplate::factory()->create(['business_id' => $business->id, 'amount' => 500000]);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/fixed-expense-templates/{$template->id}/register-now",
            ['year' => 2026, 'month' => 5]
        )->assertCreated();

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/fixed-expense-templates/{$template->id}/register-now",
            ['year' => 2026, 'month' => 5]
        )->assertStatus(422);

        $this->assertDatabaseCount('expenses', 1);
    }

    public function test_register_now_requires_an_amount_when_the_template_has_none(): void
    {
        [$business, $user] = $this->businessAndAdmin();
        $template = FixedExpenseTemplate::factory()->create(['business_id' => $business->id, 'amount' => null]);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/fixed-expense-templates/{$template->id}/register-now",
            ['year' => 2026, 'month' => 6]
        )->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_register_now_accepts_an_amount_override(): void
    {
        [$business, $user] = $this->businessAndAdmin();
        $template = FixedExpenseTemplate::factory()->create(['business_id' => $business->id, 'amount' => null]);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/fixed-expense-templates/{$template->id}/register-now",
            ['year' => 2026, 'month' => 6, 'amount' => 250000]
        )->assertCreated()->assertJsonPath('value', '250000.00');
    }

    public function test_toggle_reminder_activates_and_deactivates_a_monthly_reminder(): void
    {
        [$business, $user] = $this->businessAndAdmin();
        $template = FixedExpenseTemplate::factory()->create([
            'business_id' => $business->id, 'name' => 'Arriendo', 'day_of_month' => 5,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/fixed-expense-templates/{$template->id}/toggle-reminder")
            ->assertOk()->assertJsonPath('active', true);

        $this->assertDatabaseHas('reminders', [
            'remindable_type' => FixedExpenseTemplate::class,
            'remindable_id' => $template->id,
            'status' => 'pending',
            'recurrence' => 'monthly',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/fixed-expense-templates/{$template->id}/toggle-reminder")
            ->assertOk()->assertJsonPath('active', false);

        $this->assertDatabaseMissing('reminders', [
            'remindable_type' => FixedExpenseTemplate::class,
            'remindable_id' => $template->id,
        ]);
    }

    public function test_toggle_reminder_requires_a_day_of_month(): void
    {
        [$business, $user] = $this->businessAndAdmin();
        $template = FixedExpenseTemplate::factory()->create(['business_id' => $business->id, 'day_of_month' => null]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/fixed-expense-templates/{$template->id}/toggle-reminder")
            ->assertStatus(422);
    }
}
