<?php

namespace Tests\Feature\Api\V1;

use App\Mail\NewUserCredentialsMail;
use App\Models\Business;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Los tests corren dentro de una transaccion y algunas capas cachean
        // la lista de permisos - sincronizar antes de cada test es lo mas
        // simple para asegurar que exist:permissions:name resuelva.
        PermissionCatalog::sync();
    }

    private function actingAdmin(): array
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $admin->assignRole('admin');

        return [$admin, $business];
    }

    public function test_admin_can_list_the_business_team(): void
    {
        [$admin, $business] = $this->actingAdmin();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        User::factory()->create(); // otro negocio

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/employees')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_permission_catalog_endpoint_returns_categories(): void
    {
        [$admin] = $this->actingAdmin();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/employees/permission-catalog');

        $response->assertOk();
        $keys = collect($response->json('categories'))->pluck('key')->all();
        $this->assertContains('ventas', $keys);
        $this->assertContains('administracion', $keys);
    }

    public function test_admin_can_create_an_employee_with_permissions_and_sends_credentials(): void
    {
        Mail::fake();
        [$admin] = $this->actingAdmin();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/employees', [
            'name' => 'Empleado Nuevo',
            'email' => 'empleado@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => 'employee',
            'permissions' => ['cash_shift.manage', 'reports.sales'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('role', 'employee')
            ->assertJsonPath('permissions', ['cash_shift.manage', 'reports.sales']);

        Mail::assertSent(NewUserCredentialsMail::class, fn ($mail) => $mail->hasTo('empleado@example.com'));
    }

    public function test_updating_permissions_of_an_admin_user_is_rejected(): void
    {
        [$admin, $business] = $this->actingAdmin();
        $anotherAdmin = User::factory()->create(['business_id' => $business->id]);
        $anotherAdmin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/employees/{$anotherAdmin->id}/permissions", ['permissions' => ['reports.sales']])
            ->assertStatus(422);
    }

    public function test_admin_can_replace_the_permission_set_of_an_employee(): void
    {
        [$admin, $business] = $this->actingAdmin();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $employee->syncPermissions(['reports.sales']);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/employees/{$employee->id}/permissions", [
                'permissions' => ['cash_shift.manage', 'inventory.view'],
            ]);

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            ['cash_shift.manage', 'inventory.view'],
            $employee->fresh()->getDirectPermissions()->pluck('name')->all()
        );
    }

    public function test_promoting_an_employee_to_admin_clears_direct_permissions(): void
    {
        [$admin, $business] = $this->actingAdmin();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $employee->syncPermissions(['cash_shift.manage']);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/employees/{$employee->id}", [
            'name' => $employee->name,
            'email' => $employee->email,
            'role' => 'admin',
        ])->assertOk();

        $this->assertCount(0, $employee->fresh()->getDirectPermissions());
        $this->assertTrue($employee->fresh()->hasRole('admin'));
        // Y sigue teniendo acceso a todos los permisos, ahora heredados por rol.
        $this->assertTrue($employee->fresh()->can('cash_shift.manage'));
    }

    public function test_cannot_delete_business_owner(): void
    {
        [$admin] = $this->actingAdmin();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/employees/{$admin->id}")
            ->assertStatus(422);
    }

    public function test_cannot_delete_yourself(): void
    {
        [$admin, $business] = $this->actingAdmin();
        $anotherAdmin = User::factory()->create(['business_id' => $business->id]);
        $anotherAdmin->assignRole('admin');

        $this->actingAs($anotherAdmin, 'sanctum')
            ->deleteJson("/api/v1/employees/{$anotherAdmin->id}")
            ->assertStatus(422);
    }

    public function test_cannot_delete_an_active_employee(): void
    {
        [$admin, $business] = $this->actingAdmin();
        $employee = User::factory()->create(['business_id' => $business->id, 'is_active' => true]);
        $employee->assignRole('employee');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/employees/{$employee->id}")
            ->assertStatus(422);
    }

    public function test_can_delete_an_inactive_employee(): void
    {
        [$admin, $business] = $this->actingAdmin();
        $employee = User::factory()->create(['business_id' => $business->id, 'is_active' => false]);
        $employee->assignRole('employee');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/employees/{$employee->id}")
            ->assertNoContent();
    }

    public function test_cannot_manage_users_of_another_business(): void
    {
        [$admin] = $this->actingAdmin();
        $foreign = User::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/employees/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_a_regular_employee_cannot_manage_the_team(): void
    {
        [$owner, $business] = $this->actingAdmin();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/v1/employees', [
                'name' => 'X', 'email' => 'x@x.com', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            ])
            ->assertForbidden();
    }

    /**
     * Bug real: toggle()/destroy() usaban Request llano sin chequear
     * hasRole('admin') (a diferencia de store/update/updatePermissions, que
     * lo exigen via su FormRequest) - cualquier empleado autenticado podia
     * desactivar o borrar a un companero.
     */
    public function test_a_regular_employee_cannot_toggle_a_coworker(): void
    {
        [$owner, $business] = $this->actingAdmin();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $coworker = User::factory()->create(['business_id' => $business->id]);
        $coworker->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->patchJson("/api/v1/employees/{$coworker->id}/toggle")
            ->assertForbidden();

        $this->assertTrue($coworker->fresh()->is_active);
    }

    public function test_a_regular_employee_cannot_delete_a_coworker(): void
    {
        [$owner, $business] = $this->actingAdmin();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $coworker = User::factory()->create(['business_id' => $business->id, 'is_active' => false]);
        $coworker->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->deleteJson("/api/v1/employees/{$coworker->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $coworker->id]);
    }

    // --- Feature flag permissions_management: gatea solo la edicion de
    // permisos granulares, no el alta/baja basica de empleados. ---

    private function actingAdminWithoutPermissionsManagement(): array
    {
        $business = Business::factory()->create(['feature_flags' => ['permissions_management' => false]]);
        $admin = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $admin->assignRole('admin');

        return [$admin, $business];
    }

    public function test_permission_catalog_endpoint_is_blocked_without_the_feature(): void
    {
        [$admin] = $this->actingAdminWithoutPermissionsManagement();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/employees/permission-catalog')
            ->assertForbidden();
    }

    public function test_updating_employee_permissions_is_blocked_without_the_feature(): void
    {
        [$admin, $business] = $this->actingAdminWithoutPermissionsManagement();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/employees/{$employee->id}/permissions", ['permissions' => ['reports.sales']])
            ->assertForbidden();
    }

    public function test_can_still_list_and_create_employees_without_the_feature(): void
    {
        Mail::fake();
        [$admin] = $this->actingAdminWithoutPermissionsManagement();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/employees')
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/employees', [
                'name' => 'Empleado Nuevo',
                'email' => 'empleado@example.com',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'employee',
            ])
            ->assertCreated();
    }
}
