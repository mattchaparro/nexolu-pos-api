<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret123',
            'device_name' => 'phpunit',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email']])
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'phpunit',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_fails_for_inactive_user(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret123',
            'device_name' => 'phpunit',
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_fetch_profile_and_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phpunit')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/logout')
            ->assertNoContent();
    }

    public function test_guest_cannot_access_protected_routes(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_guest_without_an_accept_header_still_gets_json_401(): void
    {
        // Regression test: a plain client that doesn't send Accept:
        // application/json (curl, some mobile HTTP clients) must not be
        // redirected to a non-existent "login" web route.
        $this->get('/api/v1/me')->assertUnauthorized();
    }

    /**
     * Los permisos que ve el frontend en /me son los EFECTIVOS: heredados por
     * rol para un admin, directos para un empleado. La UI necesita esa lista
     * unificada para decidir que ocultar - no es aceptable que un admin
     * aparezca sin permisos por accidente.
     */
    public function test_me_returns_effective_permissions_admin_inherits_from_role(): void
    {
        PermissionCatalog::sync();
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/me');

        $response->assertOk();
        $permissions = $response->json('permissions');
        $this->assertContains('cash_shift.manage', $permissions);
        $this->assertContains('sales.reverse', $permissions);
    }

    public function test_me_returns_only_direct_permissions_for_an_employee(): void
    {
        PermissionCatalog::sync();
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $employee->syncPermissions(['cash_shift.manage']);

        $response = $this->actingAs($employee, 'sanctum')->getJson('/api/v1/me');

        $response->assertOk();
        $permissions = $response->json('permissions');
        $this->assertContains('cash_shift.manage', $permissions);
        $this->assertNotContains('sales.reverse', $permissions);
    }

    public function test_a_user_can_update_their_own_profile(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'name' => 'Vieja',
            'last_name' => 'Apellido',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/me', [
                'name' => 'Nueva',
                'last_name' => 'Apellido Nuevo',
                'email' => 'nueva@example.com',
                'cellphone' => '3001234567',
            ])
            ->assertOk();

        $response->assertJsonPath('name', 'Nueva')
            ->assertJsonPath('last_name', 'Apellido Nuevo')
            ->assertJsonPath('email', 'nueva@example.com')
            ->assertJsonPath('cellphone', '3001234567');

        $this->assertSame('Nueva', $user->fresh()->name);
    }

    public function test_updating_the_profile_rejects_an_email_already_used_by_another_account(): void
    {
        $business = Business::factory()->create();
        User::factory()->create(['business_id' => $business->id, 'email' => 'tomado@example.com']);
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/me', [
                'name' => $user->name,
                'email' => 'tomado@example.com',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_updating_the_profile_allows_keeping_the_users_own_current_email(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id, 'email' => 'yo@example.com']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/me', [
                'name' => $user->name,
                'email' => 'yo@example.com',
            ])
            ->assertOk();
    }

    public function test_a_user_can_change_their_own_password_with_the_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/me/password', [
                'current_password' => 'old-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_changing_password_rejects_an_incorrect_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/me/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_changing_password_requires_confirmation_to_match(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/me/password', [
                'current_password' => 'old-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'does-not-match',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_guest_cannot_update_profile_or_password(): void
    {
        $this->putJson('/api/v1/me', ['name' => 'X'])->assertUnauthorized();
        $this->putJson('/api/v1/me/password', ['current_password' => 'x', 'password' => 'x'])->assertUnauthorized();
    }
}
