<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashShift;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Administrar sedes y a quien entra a cada una: la pantalla de Ajustes de un
 * negocio multisede.
 */
class BranchManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        BranchContext::forget();

        parent::tearDown();
    }

    public function test_an_admin_creates_a_branch_and_can_switch_to_it_right_away(): void
    {
        [$business, , $admin] = $this->scenario();

        $branch = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/branches', [
            'name' => 'Centro comercial',
            'code' => 'CC',
            'invoice_prefix' => 'CC',
            'address' => 'Local 204',
        ])->assertCreated()->json();

        $this->assertFalse($branch['is_main'], 'La principal no se elige creando otra sede.');
        $this->assertSame('CC', $branch['invoice_prefix']);

        // Si el admin no quedara asignado, acabaria de abrir una sede a la
        // que no se puede cambiar.
        $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $branch['id'])
            ->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonPath('current_branch_id', $branch['id']);
    }

    public function test_a_duplicate_code_within_the_business_is_rejected(): void
    {
        [$business, , $admin] = $this->scenario();
        Branch::factory()->for($business)->create(['code' => 'CC']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/branches', [
            'name' => 'Otra', 'code' => 'CC',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_the_main_branch_cannot_be_deactivated(): void
    {
        [, $main, $admin] = $this->scenario();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/branches/{$main->id}/deactivate")
            ->assertStatus(422)->assertJsonValidationErrors('branch');

        $this->assertTrue($main->fresh()->is_active);
    }

    public function test_a_branch_with_an_open_cash_shift_cannot_be_deactivated(): void
    {
        [$business, , $admin] = $this->scenario();
        $second = Branch::factory()->for($business)->create();

        BranchContext::set($second);
        CashShift::factory()->create([
            'business_id' => $business->id, 'user_id' => $admin->id, 'closed_at' => null,
        ]);
        BranchContext::forget();

        // Apagarla dejaria ese turno sin poder cerrarse: no se puede arquear
        // en una sede apagada y el dinero de la jornada queda en el limbo.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/branches/{$second->id}/deactivate")
            ->assertStatus(422)->assertJsonValidationErrors('branch');

        $this->assertTrue($second->fresh()->is_active);
    }

    public function test_promoting_a_branch_to_main_demotes_the_previous_one(): void
    {
        [$business, $main, $admin] = $this->scenario();
        $second = Branch::factory()->for($business)->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/branches/{$second->id}", ['is_main' => true])
            ->assertOk()->assertJsonPath('is_main', true);

        $this->assertFalse($main->fresh()->is_main);
    }

    public function test_an_employee_cannot_manage_branches(): void
    {
        [$business] = $this->scenario();
        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/v1/branches', ['name' => 'Mia'])
            ->assertForbidden();
    }

    public function test_assigning_an_employee_to_branches_limits_where_they_can_work(): void
    {
        [$business, $main, $admin] = $this->scenario();
        $second = Branch::factory()->for($business)->create();
        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);
        $employee->assignRole('employee');
        $employee->branches()->attach([$main->id]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/employees/{$employee->id}", [
            'name' => $employee->name,
            'email' => $employee->email,
            'branch_ids' => [$second->id],
        ])->assertOk()->assertJsonPath('branch_ids', [$second->id]);

        // Y la sede por defecto se mueve con el: si quedara apuntando a una
        // sede que ya no tiene, entraria a otra sin explicacion visible.
        $this->assertSame($second->id, (int) $employee->fresh()->default_branch_id);

        $this->actingAs($employee->fresh(), 'sanctum')
            ->withHeader('X-Branch-Id', (string) $main->id)
            ->getJson('/api/v1/branches')
            ->assertStatus(403);
    }

    /**
     * El formulario de un negocio monosede no manda branch_ids. Tomarlo como
     * "ninguna sede" dejaria al empleado sin poder entrar a la unica que hay.
     */
    public function test_updating_an_employee_without_sending_branches_keeps_theirs(): void
    {
        [$business, $main, $admin] = $this->scenario();
        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);
        $employee->assignRole('employee');
        $employee->branches()->attach([$main->id]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/employees/{$employee->id}", [
            'name' => 'Nombre nuevo',
            'email' => $employee->email,
        ])->assertOk()->assertJsonPath('branch_ids', [$main->id]);
    }

    /** @return array{0: Business, 1: Branch, 2: User} */
    private function scenario(): array
    {
        $business = Business::factory()->create([
            'feature_flags' => ['multi_branch' => true, 'cash_closing' => true, 'permissions_management' => true],
        ]);
        $main = Branch::factory()->for($business)->main()->create();

        $admin = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $admin->assignRole('admin');
        $admin->branches()->attach([$main->id]);

        return [$business, $main, $admin];
    }
}
