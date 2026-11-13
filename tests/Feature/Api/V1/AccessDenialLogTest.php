<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\LogAction;
use App\Models\User;
use App\Support\AccessDenialLogger;
use App\Support\BusinessFeaturePresets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

/**
 * Cada "no tienes permiso" deja rastro: quién, qué le faltó, qué pidió y
 * desde qué pantalla. Sirve para encontrar las pantallas del POS que
 * consultan cosas que el usuario no puede ver.
 */
class AccessDenialLogTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    /** @return array{0: Business, 1: User} */
    private function employee(array $flags = []): array
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), ...$flags],
        ]);
        $employee = User::factory()->create(['business_id' => $business->id]);

        return [$business, $employee];
    }

    private function denials(User $user): Collection
    {
        return LogAction::where('action', AccessDenialLogger::ACTION)->where('user_id', $user->id)->get();
    }

    public function test_a_missing_permission_is_recorded_with_the_screen_it_came_from(): void
    {
        [$business, $employee] = $this->employee();

        $this->actingAs($employee, 'sanctum')
            ->withHeaders([AccessDenialLogger::SCREEN_HEADER => 'sales.create'])
            ->getJson('/api/v1/clients')
            ->assertForbidden();

        $denial = $this->denials($employee)->sole();
        $this->assertSame($business->id, $denial->business_id);
        $this->assertSame('GET', $denial->method);
        $this->assertSame('permission', $denial->details['reason']);
        $this->assertSame(['clients.manage'], $denial->details['required']);
        $this->assertSame('sales.create', $denial->details['screen']);
        $this->assertNotEmpty($denial->details['endpoint']);
    }

    public function test_an_admin_only_route_and_a_disabled_module_are_recorded_too(): void
    {
        [, $employee] = $this->employee(['kitchen_board' => false]);

        $this->actingAs($employee, 'sanctum')->getJson('/api/v1/link-page')->assertForbidden();
        $this->actingAs($employee, 'sanctum')->getJson('/api/v1/kitchen/tickets')->assertForbidden();

        $reasons = $this->denials($employee)->map(fn (LogAction $row) => $row->details['reason'])->sort()->values()->all();
        $this->assertSame(['admin', 'feature'], $reasons);

        $feature = $this->denials($employee)->first(fn (LogAction $row) => $row->details['reason'] === 'feature');
        $this->assertSame(['kitchen_board'], $feature->details['required']);
    }

    /** Una pantalla que consulta cada pocos segundos no llena la tabla. */
    public function test_the_same_denial_is_not_recorded_again_right_away(): void
    {
        [, $employee] = $this->employee();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($employee, 'sanctum')->getJson('/api/v1/clients')->assertForbidden();
        }
        $this->assertCount(1, $this->denials($employee));

        // Otro endpoint sí es otro rastro.
        $this->actingAs($employee, 'sanctum')->getJson('/api/v1/expenses')->assertForbidden();
        $this->assertCount(2, $this->denials($employee));
    }

    public function test_a_screen_name_that_is_not_a_route_name_is_dropped(): void
    {
        [, $employee] = $this->employee();

        $this->actingAs($employee, 'sanctum')
            ->withHeaders([AccessDenialLogger::SCREEN_HEADER => '<script>alert(1)</script>'])
            ->getJson('/api/v1/clients')
            ->assertForbidden();

        $this->assertNull($this->denials($employee)->sole()->details['screen']);
    }

    public function test_nothing_is_recorded_when_access_is_granted(): void
    {
        [, $employee] = $this->employee();
        $employee->syncPermissions(['clients.manage']);

        $this->actingAs($employee, 'sanctum')->getJson('/api/v1/clients')->assertOk();

        $this->assertCount(0, $this->denials($employee));
    }

    /** Es diagnóstico de Nexolú: el dueño no lo ve en su auditoría; el superadmin sí. */
    public function test_denials_show_in_the_global_audit_but_not_in_the_business_one(): void
    {
        [$business, $employee] = $this->employee();
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $owner->assignRole('admin');

        $this->actingAs($employee, 'sanctum')->getJson('/api/v1/clients')->assertForbidden();

        $own = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/audit-logs')->assertOk()->json('data');
        $this->assertNotContains(AccessDenialLogger::ACTION, array_column($own, 'action'));

        $superadmin = $this->superadmin();
        $global = $this->actingAs($superadmin, 'sanctum')
            ->getJson('/api/v1/superadmin/audit-logs?action='.AccessDenialLogger::ACTION.'&business_id='.$business->id)
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $global);
        $this->assertSame('Acceso denegado (permiso o módulo)', $global[0]['action_label']);

        // El código lleva un punto, así que se lee como arreglo y no como ruta.
        $actions = $this->actingAs($superadmin, 'sanctum')
            ->getJson('/api/v1/superadmin/audit-logs/actions')
            ->assertOk()
            ->json();
        $this->assertSame('Acceso denegado (permiso o módulo)', $actions[AccessDenialLogger::ACTION]);
    }
}
