<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\LogAction;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::sync();
    }

    private function userWithPermission(Business $business): User
    {
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->syncPermissions(['audit_logs.view']);

        return $user;
    }

    public function test_lists_only_the_users_own_business_entries(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = $this->userWithPermission($business);

        LogAction::create(['action' => 'product.created', 'business_id' => $business->id]);
        LogAction::create(['action' => 'expense.created', 'business_id' => $otherBusiness->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/audit-logs');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'product.created')
            ->assertJsonPath('data.0.action_label', 'Producto creado');
    }

    public function test_can_search_by_action(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermission($business);

        LogAction::create(['action' => 'product.created', 'business_id' => $business->id]);
        LogAction::create(['action' => 'expense.created', 'business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/audit-logs?search=product')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'product.created');
    }

    public function test_sorts_by_action(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermission($business);

        LogAction::create(['action' => 'sale.created', 'business_id' => $business->id]);
        LogAction::create(['action' => 'expense.created', 'business_id' => $business->id]);

        $ascending = $this->actingAs($user, 'sanctum')->getJson('/api/v1/audit-logs?sort=action&direction=asc');
        $ascending->assertOk();
        $this->assertSame(['expense.created', 'sale.created'], collect($ascending->json('data'))->pluck('action')->all());

        $descending = $this->actingAs($user, 'sanctum')->getJson('/api/v1/audit-logs?sort=action&direction=desc');
        $descending->assertOk();
        $this->assertSame(['sale.created', 'expense.created'], collect($descending->json('data'))->pluck('action')->all());
    }

    public function test_ignores_unsupported_sort_and_falls_back_to_default(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermission($business);

        LogAction::create(['action' => 'product.created', 'business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/audit-logs?sort=not_a_real_column')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_excludes_actions_taken_by_a_superadmin_impersonating_the_business(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        $owner = $this->userWithPermission($business);
        $owner->assignRole('admin');

        // Real: el dueño abre y cierra su propio turno (no puede quedar
        // abierto - CashShiftService rechaza un segundo turno abierto para
        // el mismo usuario, y la impersonacion actua como ese mismo user_id).
        $realShiftId = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/cash-shifts', ['opening_cash' => 50000])
            ->assertCreated()
            ->json('id');
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/cash-shifts/{$realShiftId}/close", ['counted_cash' => 50000])
            ->assertOk();

        // El superadmin impersona al dueño y abre OTRO turno "como" el.
        $impersonateToken = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/superadmin/impersonate/{$owner->id}")
            ->assertOk()
            ->json('token');
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$impersonateToken}")
            ->postJson('/api/v1/cash-shifts', ['opening_cash' => 99999])
            ->assertCreated();
        $this->app['auth']->forgetGuards();

        // La accion impersonada quedo marcada en la BD...
        $impersonatedLog = LogAction::where('action', 'cash_shift.opened')
            ->where('details->opening_cash', 99999)
            ->first();
        $this->assertNotNull($impersonatedLog);
        $this->assertSame($admin->id, $impersonatedLog->details['impersonated_by_superadmin_id']);

        // ...pero el dueño del negocio, mirando SU auditoria, solo ve la
        // suya - no la que el superadmin hizo "como" el.
        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/audit-logs?search=cash_shift.opened')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame(50000.0, (float) $response->json('data.0.details.opening_cash'));
    }

    /**
     * A diferencia de una accion cualquiera hecha DURANTE la impersonacion
     * (cubierto arriba), el evento de "empezar a impersonar" en si mismo es
     * un caso especial: en el momento en que ImpersonateController::start()
     * audita, la request todavia esta autenticada con el token REAL del
     * superadmin (el de impersonacion recien se crea, no es el "actual" de
     * esa request) - AuditLogger::log() no podia detectarlo solo, por eso
     * ImpersonateController::start() lo pasa a mano. Bug real reportado: el
     * dueño del negocio veia "superadmin.impersonation.started" en SU
     * propia auditoria.
     */
    public function test_excludes_the_impersonation_started_event_itself_from_the_business_audit_log(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        $owner = $this->userWithPermission($business);
        $owner->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/superadmin/impersonate/{$owner->id}")
            ->assertOk();

        $startedLog = LogAction::where('action', 'superadmin.impersonation.started')->first();
        $this->assertNotNull($startedLog);
        $this->assertSame($admin->id, $startedLog->details['impersonated_by_superadmin_id']);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/audit-logs?search=impersonation')
            ->assertOk();

        $response->assertJsonCount(0, 'data');
    }

    public function test_requires_the_audit_logs_view_permission(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/audit-logs')
            ->assertForbidden();
    }

    public function test_blocked_when_the_business_lacks_the_audit_logs_feature(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['audit_logs' => false]]);
        $user = $this->userWithPermission($business);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/audit-logs')
            ->assertForbidden();
    }

    public function test_actions_returns_the_dictionary(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermission($business);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/audit-logs/actions')
            ->assertOk();

        $this->assertSame('Producto creado', $response->json()['product.created']);
    }

    public function test_export_streams_a_csv(): void
    {
        $business = Business::factory()->create();
        $user = $this->userWithPermission($business);
        LogAction::create(['action' => 'product.created', 'business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->get('/api/v1/audit-logs/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }
}
