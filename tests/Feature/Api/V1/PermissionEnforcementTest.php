<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Expense;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cubre EnsureBusinessPermission en si mismo con un empleado real (no admin):
 * el resto de los tests de modulo (ClientTest, ExpenseTest, etc.) siempre
 * actuan como admin, que bypassa por rol y nunca ejercita la rama de
 * "empleado con/sin el permiso directo".
 */
class PermissionEnforcementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::sync();
    }

    public function test_an_employee_without_the_permission_is_rejected(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/clients')
            ->assertStatus(403);
    }

    public function test_an_employee_with_the_direct_permission_is_allowed(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $employee->syncPermissions(['clients.manage']);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/clients')
            ->assertOk();
    }

    public function test_an_admin_always_passes_regardless_of_direct_permissions(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/clients')
            ->assertOk();
    }

    public function test_expenses_index_accepts_either_create_or_manage(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $employee->syncPermissions(['expenses.create']);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/expenses')
            ->assertOk();
    }

    public function test_only_expenses_manage_can_delete(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $employee->syncPermissions(['expenses.create']);
        $expense = Expense::factory()->create(['business_id' => $business->id]);

        $this->actingAs($employee, 'sanctum')
            ->deleteJson("/api/v1/expenses/{$expense->id}")
            ->assertStatus(403);

        $employee->syncPermissions(['expenses.manage']);

        $this->actingAs($employee, 'sanctum')
            ->deleteJson("/api/v1/expenses/{$expense->id}")
            ->assertNoContent();
    }

    public function test_receivables_and_layaways_use_separate_permissions(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $employee->syncPermissions(['layaways.manage']);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/layaways')
            ->assertOk();

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/receivables')
            ->assertStatus(403);
    }
}
