<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessTable;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BusinessTableTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_list_only_their_business_tables(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        BusinessTable::factory()->count(2)->create(['business_id' => $business->id]);
        BusinessTable::factory()->create(['business_id' => $otherBusiness->id]);

        // La lista de mesas no se pagina (son pocas): el resource rinde un array plano.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tables')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_creating_a_table_auto_numbers_when_no_number_is_given(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        BusinessTable::factory()->create(['business_id' => $business->id, 'number' => 4]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/tables', ['name' => 'Mesa nueva'])
            ->assertCreated()
            ->assertJsonPath('number', 5);
    }

    public function test_table_can_be_deactivated_via_update(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $table = BusinessTable::factory()->create(['business_id' => $business->id, 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/tables/{$table->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);
    }

    public function test_table_with_an_open_sale_cannot_be_deleted(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $table = BusinessTable::factory()->create(['business_id' => $business->id]);
        Sale::factory()->create([
            'business_id' => $business->id,
            'table_id' => $table->id,
            'status' => 'open',
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/tables/{$table->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('business_tables', ['id' => $table->id]);
    }

    public function test_free_table_can_be_deleted(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $table = BusinessTable::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/tables/{$table->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('business_tables', ['id' => $table->id]);
    }

    public function test_tables_module_is_blocked_when_open_tabs_feature_is_off(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['open_tabs' => false]]);
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tables')
            ->assertForbidden();
    }

    public function test_created_table_response_reflects_the_database_default_is_active(): void
    {
        // Bug real: store() no refrescaba de BD, asi que "is_active" (DEFAULT 1)
        // llegaba null al cliente si el request no lo mandaba.
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/tables', ['name' => 'Sin is_active explicito'])
            ->assertCreated()
            ->assertJsonPath('is_active', true);
    }
}
