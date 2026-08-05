<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\Business;
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
}
