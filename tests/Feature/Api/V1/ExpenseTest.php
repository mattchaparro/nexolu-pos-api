<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_list_only_their_business_expenses(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Expense::factory()->count(2)->create(['business_id' => $business->id]);
        Expense::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/expenses')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_sorts_by_value(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Expense::factory()->create(['business_id' => $business->id, 'value' => 15000]);
        Expense::factory()->create(['business_id' => $business->id, 'value' => 50000]);

        $ascending = $this->actingAs($user, 'sanctum')->getJson('/api/v1/expenses?sort=value&direction=asc');
        $ascending->assertOk();
        $this->assertSame(['15000.00', '50000.00'], collect($ascending->json('data'))->pluck('value')->all());

        $descending = $this->actingAs($user, 'sanctum')->getJson('/api/v1/expenses?sort=value&direction=desc');
        $descending->assertOk();
        $this->assertSame(['50000.00', '15000.00'], collect($descending->json('data'))->pluck('value')->all());
    }

    public function test_index_ignores_unsupported_sort_and_falls_back_to_default(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Expense::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/expenses?sort=not_a_real_column')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_user_can_create_an_expense_with_a_global_type(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $type = ExpenseType::factory()->global()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/expenses', [
            'date' => '2026-01-15',
            'description' => 'Arriendo local',
            'value' => 500000,
            'type_id' => $type->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('description', 'Arriendo local')
            ->assertJsonPath('scope', 'operacional')
            ->assertJsonPath('payment_method', Expense::PAYMENT_METHODS[0]);
    }

    public function test_expense_rejects_a_payment_method_outside_the_allowed_set(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $type = ExpenseType::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'date' => '2026-01-15',
                'description' => 'Gasto raro',
                'value' => 5000,
                'type_id' => $type->id,
                'payment_method' => 'Bitcoin',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);
    }

    public function test_expense_accepts_a_valid_payment_method(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $type = ExpenseType::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'date' => '2026-01-15',
                'description' => 'Pago Nequi',
                'value' => 5000,
                'type_id' => $type->id,
                'payment_method' => 'Nequi',
            ])
            ->assertCreated()
            ->assertJsonPath('payment_method', 'Nequi');
    }

    public function test_expense_value_below_the_minimum_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $type = ExpenseType::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'date' => '2026-01-15',
                'description' => 'Muy barato',
                'value' => 10,
                'type_id' => $type->id,
            ])
            ->assertStatus(422);
    }

    public function test_user_cannot_use_an_expense_type_from_another_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreignType = ExpenseType::factory()->create(['business_id' => $otherBusiness->id]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/expenses', [
                'date' => '2026-01-15',
                'description' => 'Gasto',
                'value' => 1000,
                'type_id' => $foreignType->id,
            ])
            ->assertStatus(422);
    }

    public function test_expense_can_be_linked_to_a_product_in_the_same_business(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $type = ExpenseType::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/expenses', [
            'date' => '2026-01-15',
            'description' => 'Reposicion',
            'value' => 20000,
            'type_id' => $type->id,
            'linkable_type' => Product::class,
            'linkable_id' => $product->id,
        ]);

        $response->assertCreated()->assertJsonPath('linkable_id', $product->id);
    }

    public function test_expenses_can_be_filtered_by_month(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        Expense::factory()->create(['business_id' => $business->id, 'date' => '2026-01-10']);
        Expense::factory()->create(['business_id' => $business->id, 'date' => '2026-03-10']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/expenses?month=1&year=2026')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', '2026-01-10');
    }

    public function test_expenses_module_is_blocked_when_the_feature_flag_is_disabled(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['expenses' => false]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/expenses')
            ->assertForbidden();
    }

    public function test_user_can_update_and_delete_an_expense(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $expense = Expense::factory()->create(['business_id' => $business->id, 'description' => 'Original']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/expenses/{$expense->id}", ['description' => 'Actualizado'])
            ->assertOk()
            ->assertJsonPath('description', 'Actualizado');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/expenses/{$expense->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
    }

    public function test_an_expense_with_a_reminder_date_creates_a_reminder(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $type = ExpenseType::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/expenses', [
            'date' => now()->toDateString(),
            'description' => 'Arriendo local',
            'value' => 1500000,
            'type_id' => $type->id,
            'reminder_date' => now()->addDays(5)->toDateString(),
            'reminder_recurrence' => 'monthly',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('reminders', [
            'remindable_type' => Expense::class,
            'remindable_id' => $response->json('id'),
            'title' => 'Gasto: Arriendo local',
            'recurrence' => 'monthly',
            'status' => 'pending',
        ]);
    }

    public function test_an_expense_without_a_reminder_date_does_not_create_a_reminder(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $type = ExpenseType::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/expenses', [
            'date' => now()->toDateString(),
            'description' => 'Papeleria',
            'value' => 20000,
            'type_id' => $type->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseMissing('reminders', ['remindable_type' => Expense::class, 'remindable_id' => $response->json('id')]);
    }
}
