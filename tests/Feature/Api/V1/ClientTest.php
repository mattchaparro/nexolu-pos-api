<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_list_only_their_business_clients(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Client::factory()->count(2)->create(['business_id' => $business->id]);
        Client::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/clients')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_user_can_search_clients_by_name_phone_or_email(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Client::factory()->create(['business_id' => $business->id, 'name' => 'Maria Perez', 'phone' => '3001112222']);
        Client::factory()->create(['business_id' => $business->id, 'name' => 'Carlos Ruiz', 'phone' => '3009998888']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/clients/search?q=Maria')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Maria Perez']);
    }

    public function test_user_can_search_clients_by_identification(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Client::factory()->create(['business_id' => $business->id, 'name' => 'Maria Perez', 'identification' => '1002003004']);
        Client::factory()->create(['business_id' => $business->id, 'name' => 'Carlos Ruiz', 'identification' => '5006007008']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/clients/search?q=1002003004')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Maria Perez']);
    }

    public function test_user_can_create_a_client(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/clients', [
            'name' => 'Nuevo Cliente',
            'phone' => '3001234567',
            'identification' => '1234567890',
        ]);

        $response->assertCreated()->assertJsonPath('name', 'Nuevo Cliente')->assertJsonPath('identification', '1234567890');
        $this->assertDatabaseHas('clients', ['name' => 'Nuevo Cliente', 'business_id' => $business->id, 'identification' => '1234567890']);
    }

    public function test_client_creation_is_blocked_past_the_plan_limit(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        Client::factory()->count(Client::LIMIT_PER_BUSINESS)->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/clients', ['name' => 'Cliente Extra'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('clients', ['name' => 'Cliente Extra']);
    }

    public function test_user_cannot_view_a_client_from_another_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $client = Client::factory()->create(['business_id' => $otherBusiness->id]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/clients/{$client->id}")
            ->assertNotFound();
    }

    public function test_clients_module_is_blocked_when_the_feature_flag_is_disabled(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['clients' => false]]);
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/clients')
            ->assertForbidden();
    }

    public function test_user_can_update_and_delete_a_client(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $client = Client::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/clients/{$client->id}", ['name' => 'Nombre Actualizado'])
            ->assertOk()
            ->assertJsonPath('name', 'Nombre Actualizado');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/clients/{$client->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('clients', ['id' => $client->id]);
    }

    /**
     * search()/store() no exigen clients.manage a proposito: cualquiera que
     * pueda vender/agendar/apartar necesita poder buscar o dar de alta un
     * cliente en el momento, sin el permiso del directorio completo.
     */
    public function test_an_employee_without_clients_manage_can_search_and_create_clients(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        Client::factory()->create(['business_id' => $business->id, 'name' => 'Maria Perez', 'phone' => '3001112222']);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/clients/search?q=Maria')
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/v1/clients', ['name' => 'Cliente Nuevo', 'phone' => '3005556666'])
            ->assertCreated();
    }

    public function test_an_employee_without_clients_manage_cannot_list_update_or_delete_clients(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $client = Client::factory()->create(['business_id' => $business->id]);

        $this->actingAs($employee, 'sanctum')->getJson('/api/v1/clients')->assertForbidden();
        $this->actingAs($employee, 'sanctum')->getJson("/api/v1/clients/{$client->id}")->assertForbidden();
        $this->actingAs($employee, 'sanctum')
            ->putJson("/api/v1/clients/{$client->id}", ['name' => 'X'])
            ->assertForbidden();
        $this->actingAs($employee, 'sanctum')->deleteJson("/api/v1/clients/{$client->id}")->assertForbidden();
    }
}
