<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Support\BranchContext;
use App\Traits\BelongsToBranch;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Contrato del contexto de sede: quien puede operar en cual local, y que ve
 * cuando lo hace.
 *
 * Es el candado equivalente a StorefrontTenantIsolationTest pero un nivel mas
 * adentro: alli se prueba que un negocio no vea a otro, aca que un empleado
 * de la sede 2 no opere sobre la sede 1 del mismo negocio.
 *
 * Se prueba contra una ruta y un modelo minimos a proposito: en esta fase
 * ningun modelo de produccion usa todavia BelongsToBranch (aplicarselo exige
 * backfillear su branch_id primero, modulo por modulo), pero la garantia
 * tiene que estar cerrada antes de que el primero lo haga.
 */
class BranchResolutionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'branch.context'])
            ->get('/api/test-branch/context', fn () => response()->json([
                'branch_id' => BranchContext::branchId(),
                'all' => BranchContext::isAllBranches(),
                'visible' => BranchScopedTable::query()->orderBy('id')->pluck('name'),
            ]));

        Route::middleware(['api', 'auth:sanctum', 'branch.context'])
            ->post('/api/test-branch/tables', fn () => response()->json([
                'branch_id' => BranchScopedTable::create(['name' => 'Mesa nueva', 'number' => 1])->branch_id,
            ]));
    }

    public function test_resolves_the_main_branch_when_the_request_does_not_ask_for_one(): void
    {
        [$business, $main] = $this->businessWithMainBranch();
        $employee = $this->employee($business, $main);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/test-branch/context')
            ->assertOk()
            ->assertJsonPath('branch_id', $main->id);
    }

    public function test_prefers_the_default_branch_over_the_main_one(): void
    {
        [$business, $main] = $this->businessWithMainBranch();
        $second = Branch::factory()->for($business)->create();
        $employee = $this->employee($business, $main, $second);
        $employee->update(['default_branch_id' => $second->id]);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/test-branch/context')
            ->assertOk()
            ->assertJsonPath('branch_id', $second->id);
    }

    public function test_header_selects_a_branch_the_employee_is_assigned_to(): void
    {
        [$business, $main] = $this->businessWithMainBranch();
        $second = Branch::factory()->for($business)->create();
        $employee = $this->employee($business, $main, $second);

        $this->actingAs($employee, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $second->id)
            ->getJson('/api/test-branch/context')
            ->assertOk()
            ->assertJsonPath('branch_id', $second->id);
    }

    public function test_employee_cannot_enter_a_branch_they_are_not_assigned_to(): void
    {
        [$business, $main] = $this->businessWithMainBranch();
        $other = Branch::factory()->for($business)->create();
        $employee = $this->employee($business, $main);

        $this->actingAs($employee, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $other->id)
            ->getJson('/api/test-branch/context')
            ->assertStatus(403);
    }

    public function test_a_branch_of_another_business_is_rejected(): void
    {
        [$business, $main] = $this->businessWithMainBranch();
        [, $foreign] = $this->businessWithMainBranch();
        $employee = $this->employee($business, $main);

        $this->actingAs($employee, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $foreign->id)
            ->getJson('/api/test-branch/context')
            ->assertStatus(403);
    }

    public function test_an_inactive_branch_is_rejected(): void
    {
        [$business, $main] = $this->businessWithMainBranch();
        $closed = Branch::factory()->for($business)->inactive()->create();
        $admin = $this->admin($business);

        $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $closed->id)
            ->getJson('/api/test-branch/context')
            ->assertStatus(403);
    }

    public function test_a_non_numeric_branch_header_is_a_bad_request(): void
    {
        [$business, $main] = $this->businessWithMainBranch();
        $employee = $this->employee($business, $main);

        $this->actingAs($employee, 'sanctum')
            ->withHeader('X-Branch-Id', 'sede-uno')
            ->getJson('/api/test-branch/context')
            ->assertStatus(400);
    }

    public function test_an_admin_reaches_any_branch_of_their_business_without_being_assigned(): void
    {
        [$business, $main] = $this->businessWithMainBranch();
        $second = Branch::factory()->for($business)->create();
        $admin = $this->admin($business);

        $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $second->id)
            ->getJson('/api/test-branch/context')
            ->assertOk()
            ->assertJsonPath('branch_id', $second->id);
    }

    public function test_only_an_admin_can_ask_for_the_consolidated_view(): void
    {
        [$business, $main] = $this->businessWithMainBranch();
        $employee = $this->employee($business, $main);
        $admin = $this->admin($business);

        $this->actingAs($employee, 'sanctum')
            ->withHeader('X-Branch-Id', 'all')
            ->getJson('/api/test-branch/context')
            ->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Branch-Id', 'all')
            ->getJson('/api/test-branch/context')
            ->assertOk()
            ->assertJsonPath('all', true)
            ->assertJsonPath('branch_id', null);
    }

    public function test_reads_are_scoped_to_the_active_branch_and_the_consolidated_view_sees_everything(): void
    {
        [$business, $main] = $this->businessWithMainBranch();
        $second = Branch::factory()->for($business)->create();
        $admin = $this->admin($business);

        $this->tableIn($business, $main, 'Mesa de la principal');
        $this->tableIn($business, $second, 'Mesa de la segunda');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/test-branch/context')
            ->assertOk()
            ->assertJsonPath('visible', ['Mesa de la principal']);

        $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $second->id)
            ->getJson('/api/test-branch/context')
            ->assertOk()
            ->assertJsonPath('visible', ['Mesa de la segunda']);

        $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Branch-Id', 'all')
            ->getJson('/api/test-branch/context')
            ->assertOk()
            ->assertJsonPath('visible', ['Mesa de la principal', 'Mesa de la segunda']);
    }

    public function test_new_rows_are_stamped_with_the_active_branch(): void
    {
        [$business, $main] = $this->businessWithMainBranch();
        $second = Branch::factory()->for($business)->create();
        $admin = $this->admin($business);

        $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $second->id)
            ->postJson('/api/test-branch/tables')
            ->assertOk()
            ->assertJsonPath('branch_id', $second->id);
    }

    /**
     * Sin sede resuelta (comandos, jobs, seeders) no se filtra nada, igual
     * que hace BelongsToBusiness sin tenant. Es lo que permite que el
     * backfill vea las filas que todavia no tienen sede.
     */
    public function test_without_a_branch_context_nothing_is_filtered(): void
    {
        [$business, $main] = $this->businessWithMainBranch();
        $second = Branch::factory()->for($business)->create();
        $this->tableIn($business, $main, 'Mesa A');
        $this->tableIn($business, $second, 'Mesa B');

        $this->assertNull(BranchContext::branchId());
        $this->assertSame(
            ['Mesa A', 'Mesa B'],
            BranchScopedTable::withoutGlobalScope('business')
                ->where('business_id', $business->id)
                ->orderBy('id')
                ->pluck('name')
                ->all()
        );
    }

    /** @return array{0: Business, 1: Branch} */
    private function businessWithMainBranch(): array
    {
        $business = Business::factory()->create();

        return [$business, Branch::factory()->for($business)->main()->create()];
    }

    private function employee(Business $business, Branch ...$branches): User
    {
        $user = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);
        $user->assignRole('employee');
        $user->branches()->attach(collect($branches)->pluck('id')->all());

        return $user;
    }

    private function admin(Business $business): User
    {
        $user = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function tableIn(Business $business, Branch $branch, string $name): void
    {
        BranchScopedTable::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'name' => $name,
            'number' => 0,
        ]);
    }
}

/**
 * Modelo minimo para ejercitar BelongsToBranch sobre una tabla real. No es
 * codigo de produccion: ningun modelo usa todavia el trait (ver el docblock
 * de la clase de test).
 */
class BranchScopedTable extends Model
{
    use BelongsToBranch, BelongsToBusiness;

    protected $table = 'business_tables';

    protected $guarded = [];
}
