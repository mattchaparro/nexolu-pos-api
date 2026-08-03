<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\ExpenseType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseTypeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_default_types_are_seeded_the_first_time_a_business_lists_them(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/expense-types');

        $response->assertOk()->assertJsonCount(count(ExpenseType::DEFAULT_NAMES));
        $this->assertDatabaseHas('expense_types', ['business_id' => $business->id, 'name' => 'Insumos']);
    }

    public function test_listing_includes_global_types_alongside_the_business_own(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        ExpenseType::factory()->global()->create(['name' => 'Tipo Global']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/expense-types');

        $response->assertOk()->assertJsonFragment(['name' => 'Tipo Global']);
    }

    public function test_business_does_not_see_another_businesss_custom_types(): void
    {
        $otherBusiness = Business::factory()->create();
        ExpenseType::factory()->create(['business_id' => $otherBusiness->id, 'name' => 'Tipo Ajeno']);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/expense-types');

        $response->assertOk()->assertJsonMissing(['name' => 'Tipo Ajeno']);
    }

    public function test_user_can_quick_create_a_custom_type(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/expense-types', [
            'name' => 'Mantenimiento',
        ]);

        $response->assertCreated()->assertJsonPath('name', 'Mantenimiento');
        $this->assertDatabaseHas('expense_types', ['business_id' => $business->id, 'name' => 'Mantenimiento']);
    }

    public function test_type_name_must_be_unique_within_the_same_business(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        ExpenseType::factory()->create(['business_id' => $business->id, 'name' => 'Mantenimiento']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/expense-types', ['name' => 'Mantenimiento'])
            ->assertStatus(422);
    }
}
