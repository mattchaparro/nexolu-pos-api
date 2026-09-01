<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * GET /v1/branches alimenta el selector de sucursal de la barra superior. Es
 * el unico endpoint que el front necesita para multisede: elegida la sede,
 * todo lo demas viaja en el header X-Branch-Id.
 */
class BranchIndexTest extends TestCase
{
    use DatabaseTransactions;

    public function test_an_admin_sees_every_branch_of_the_business_with_the_main_one_first(): void
    {
        $business = Business::factory()->create();
        $second = Branch::factory()->for($business)->create(['name' => 'Centro comercial']);
        $main = Branch::factory()->for($business)->main()->create(['name' => 'Punto de fabrica']);
        $admin = $this->admin($business);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/branches')->assertOk();

        $this->assertSame([$main->id, $second->id], array_column($response->json('data'), 'id'));
        $this->assertSame($main->id, $response->json('current_branch_id'));
        $this->assertTrue($response->json('can_view_all_branches'));
    }

    public function test_an_employee_only_sees_the_branches_they_are_assigned_to(): void
    {
        $business = Business::factory()->create();
        $main = Branch::factory()->for($business)->main()->create();
        $second = Branch::factory()->for($business)->create();
        Branch::factory()->for($business)->create();

        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);
        $employee->assignRole('employee');
        $employee->branches()->attach([$main->id, $second->id]);

        $response = $this->actingAs($employee, 'sanctum')->getJson('/api/v1/branches')->assertOk();

        $this->assertSame([$main->id, $second->id], array_column($response->json('data'), 'id'));
        // Sin esto el front le ofreceria "Todas las sedes" y recibiria un 403.
        $this->assertFalse($response->json('can_view_all_branches'));
    }

    public function test_inactive_branches_are_not_offered(): void
    {
        $business = Business::factory()->create();
        $main = Branch::factory()->for($business)->main()->create();
        Branch::factory()->for($business)->inactive()->create();

        $response = $this->actingAs($this->admin($business), 'sanctum')->getJson('/api/v1/branches')->assertOk();

        $this->assertSame([$main->id], array_column($response->json('data'), 'id'));
    }

    public function test_an_admin_can_ask_for_the_inactive_ones_too(): void
    {
        $business = Business::factory()->create();
        $main = Branch::factory()->for($business)->main()->create();
        $closed = Branch::factory()->for($business)->inactive()->create();

        // Sin esto, una sede desactivada desaparece de la unica pantalla
        // desde la que se puede volver a encender.
        $response = $this->actingAs($this->admin($business), 'sanctum')
            ->getJson('/api/v1/branches?include_inactive=1')
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$main->id, $closed->id],
            array_column($response->json('data'), 'id')
        );
    }

    public function test_an_employee_cannot_widen_the_list_with_include_inactive(): void
    {
        $business = Business::factory()->create();
        $main = Branch::factory()->for($business)->main()->create();
        Branch::factory()->for($business)->inactive()->create();

        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);
        $employee->assignRole('employee');
        $employee->branches()->attach([$main->id]);

        $response = $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/branches?include_inactive=1')
            ->assertOk();

        $this->assertSame([$main->id], array_column($response->json('data'), 'id'));
    }

    public function test_it_reports_the_consolidated_mode_when_asked_for_it(): void
    {
        $business = Business::factory()->create();
        Branch::factory()->for($business)->main()->create();

        $response = $this->actingAs($this->admin($business), 'sanctum')
            ->withHeader('X-Branch-Id', 'all')
            ->getJson('/api/v1/branches')
            ->assertOk();

        $this->assertTrue($response->json('all_branches'));
        $this->assertNull($response->json('current_branch_id'));
    }

    public function test_a_monosede_business_gets_its_single_branch(): void
    {
        $business = Business::factory()->create();
        $main = Branch::factory()->for($business)->main()->create();

        $response = $this->actingAs($this->admin($business), 'sanctum')->getJson('/api/v1/branches')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($main->id, $response->json('current_branch_id'));
    }

    public function test_the_invoice_prefix_falls_back_to_the_business_one(): void
    {
        $business = Business::factory()->create(['invoice_prefix' => 'NEX']);
        Branch::factory()->for($business)->main()->create(['invoice_prefix' => null]);
        Branch::factory()->for($business)->create(['invoice_prefix' => 'CC', 'name' => 'Centro comercial']);

        $response = $this->actingAs($this->admin($business), 'sanctum')->getJson('/api/v1/branches')->assertOk();

        $this->assertSame(['NEX', 'CC'], array_column($response->json('data'), 'invoice_prefix'));
    }

    private function admin(Business $business): User
    {
        $user = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $user->assignRole('admin');

        return $user;
    }
}
