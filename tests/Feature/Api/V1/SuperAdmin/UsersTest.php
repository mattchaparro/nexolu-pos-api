<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_superadmin_can_list_and_filter_users_by_business(): void
    {
        $admin = $this->superadmin();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        User::factory()->create(['business_id' => $businessA->id]);
        User::factory()->create(['business_id' => $businessB->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/users')
            ->assertOk()
            ->assertJsonCount(3, 'data'); // + el propio superadmin

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/superadmin/users?business_id={$businessA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_superadmin_can_create_a_user_for_a_business_with_a_role(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/users', [
            'name' => 'Nuevo Empleado',
            'email' => 'empleado@example.com',
            'password' => 'secret123',
            'business_id' => $business->id,
            'role' => 'employee',
        ]);

        $response->assertCreated()->assertJsonPath('roles.0', 'employee');
        $this->assertDatabaseHas('users', ['email' => 'empleado@example.com', 'business_id' => $business->id]);
        $this->assertDatabaseHas('log_actions', ['action' => 'superadmin.user.created']);
    }

    public function test_toggle_flips_is_active(): void
    {
        $admin = $this->superadmin();
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/superadmin/users/{$user->id}/toggle")
            ->assertOk()
            ->assertJsonPath('is_active', false);
    }

    public function test_reset_password_returns_the_new_password_once_and_never_persists_it_in_clear(): void
    {
        $admin = $this->superadmin();
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/superadmin/users/{$user->id}/reset-password");

        $response->assertOk()->assertJsonStructure(['password']);
        $newPassword = $response->json('password');

        $user->refresh();
        $this->assertTrue(Hash::check($newPassword, $user->password));
        // La app nunca escribe plain_password (a diferencia del legacy): la
        // columna sigue en el schema compartido, pero esta app no la usa.
        $this->assertDatabaseMissing('users', ['id' => $user->id, 'plain_password' => $newPassword]);
    }
}
