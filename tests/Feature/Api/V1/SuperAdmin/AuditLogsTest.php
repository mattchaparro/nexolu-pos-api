<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\Business;
use App\Models\LogAction;
use App\Support\AuditActionDictionary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class AuditLogsTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_superadmin_actions_are_logged_and_listable(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();

        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/superadmin/businesses/{$business->id}/toggle")->assertOk();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/audit-logs');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'superadmin.business.toggled');
    }

    public function test_can_filter_by_business_id(): void
    {
        $admin = $this->superadmin();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/superadmin/businesses/{$businessA->id}/toggle")->assertOk();
        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/superadmin/businesses/{$businessB->id}/toggle")->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/superadmin/audit-logs?business_id={$businessA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.business_id', $businessA->id);
    }

    public function test_export_streams_a_csv(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/superadmin/businesses/{$business->id}/toggle")->assertOk();

        $response = $this->actingAs($admin, 'sanctum')->get('/api/v1/superadmin/audit-logs/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_rows_carry_a_readable_label_so_the_panel_never_shows_raw_codes(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();

        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/superadmin/businesses/{$business->id}/toggle")->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action_label', AuditActionDictionary::label('superadmin.business.toggled'));
    }

    /**
     * `action` filtra por codigo exacto y `search` por texto libre. Con LIKE,
     * elegir "Venta creada (administrador)" en el desplegable tambien traeria
     * las del empleado (sale.created.employee), que es justo lo que el
     * desplegable viene a evitar.
     */
    public function test_the_action_filter_is_exact_not_a_like(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();

        LogAction::create(['business_id' => $business->id, 'action' => 'sale.created', 'user_id' => $admin->id]);
        LogAction::create(['business_id' => $business->id, 'action' => 'sale.created.employee', 'user_id' => $admin->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/audit-logs?action=sale.created')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'sale.created');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/audit-logs?search=sale.created')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_actions_dictionary_is_reachable_without_the_business_audit_feature(): void
    {
        $admin = $this->superadmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/audit-logs/actions')
            ->assertOk();

        // Sin json('sale.created'): el punto es notacion de path anidado, y
        // las claves de este diccionario LLEVAN puntos.
        $this->assertSame(AuditActionDictionary::label('sale.created'), $response->json()['sale.created']);
    }
}
