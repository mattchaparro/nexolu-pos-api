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
        $this->assertContains('permissions.manage', $permissions);
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
        $this->assertNotContains('permissions.manage', $permissions);
    }
}
