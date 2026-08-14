<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\LogAction;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use DatabaseTransactions;

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
