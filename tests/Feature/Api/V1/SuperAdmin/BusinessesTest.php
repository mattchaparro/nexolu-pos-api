<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\Business;
use App\Models\SaasSubscriptionPayment;
use App\Models\Sale;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class BusinessesTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_a_regular_business_user_cannot_access_the_superadmin_panel(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/superadmin/businesses')
            ->assertForbidden();
    }

    public function test_superadmin_can_list_and_filter_businesses(): void
    {
        $admin = $this->superadmin();
        Business::factory()->create(['name' => 'Activo Inc', 'active' => true, 'paid_until' => now()->addDays(10)]);
        Business::factory()->create(['name' => 'Inactivo SA', 'active' => false]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/businesses')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/businesses?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Inactivo SA');
    }

    public function test_superadmin_can_create_a_business_with_a_direct_plan(): void
    {
        $admin = $this->superadmin();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/businesses', [
            'business_name' => 'Nuevo Negocio',
            'owner_name' => 'Dueño Nuevo',
            'email' => 'dueno@example.com',
            'password' => 'secret123',
            'plan' => 'full',
        ]);

        $response->assertCreated()
            ->assertJsonPath('subscription_plan', 'full')
            ->assertJsonPath('feature_flags.open_tabs', true);

        $this->assertDatabaseHas('users', ['email' => 'dueno@example.com', 'business_id' => $response->json('id')]);
        $this->assertDatabaseHas('log_actions', ['action' => 'superadmin.business.created']);
    }

    public function test_show_includes_stats_and_roles_summary(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        $seller = User::factory()->create(['business_id' => $business->id]);
        $seller->assignRole('employee');

        Sale::factory()->create([
            'business_id' => $business->id,
            'status' => 'closed',
            'total' => 10000,
            'user_id' => $seller->id,
            'closed_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/superadmin/businesses/{$business->id}");

        $response->assertOk()
            ->assertJsonPath('stats.closed_sales_last_30_days', 1)
            ->assertJsonPath('stats.revenue_last_30_days', 10000)
            ->assertJsonPath('stats.open_support_tickets', 0);

        $roles = collect($response->json('roles_summary'))->pluck('role')->all();
        $this->assertContains('employee', $roles);

        $team = collect($response->json('team'));
        $this->assertCount(1, $team);
        $this->assertSame($seller->id, $team->first()['id']);
        $this->assertContains('employee', $team->first()['roles']);
        $this->assertArrayHasKey('last_active_at', $team->first());
    }

    public function test_index_reports_last_activity_from_audit_log(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/superadmin/businesses/{$business->id}/toggle")->assertOk();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/businesses');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $business->id);
        $this->assertNotNull($row['last_activity_at']);
    }

    public function test_show_reports_the_users_last_active_at_from_their_tokens(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        $seller = User::factory()->create(['business_id' => $business->id]);
        $token = $seller->createToken('phpunit')->accessToken;
        $token->forceFill(['last_used_at' => now()->subHour()])->save();

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/superadmin/businesses/{$business->id}");

        $team = collect($response->json('team'));
        $this->assertNotNull($team->first()['last_active_at']);
    }

    public function test_show_counts_open_support_tickets(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        SupportTicket::factory()->create(['business_id' => $business->id, 'status' => 'open']);
        SupportTicket::factory()->create(['business_id' => $business->id, 'status' => 'resolved']);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/superadmin/businesses/{$business->id}");

        $response->assertOk()->assertJsonPath('stats.open_support_tickets', 1);
    }

    public function test_activate_extends_paid_until_changes_plan_and_records_a_payment(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create(['subscription_plan' => 'basic', 'paid_until' => null]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/superadmin/businesses/{$business->id}/activate", [
            'days' => 30,
            'plan' => 'full',
        ]);

        $response->assertCreated()->assertJsonPath('amount_cop', 85000);

        $business->refresh();
        $this->assertSame('full', $business->subscription_plan);
        $this->assertNotNull($business->paid_until);
        $this->assertTrue($business->paid_until->isFuture());
        $this->assertDatabaseHas('saas_subscription_payments', ['business_id' => $business->id, 'amount_cop' => 85000]);
    }

    public function test_activate_uses_the_custom_price_when_set(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create(['custom_price_cop' => 40000]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/superadmin/businesses/{$business->id}/activate", [
            'days' => 30,
        ]);

        $response->assertCreated()->assertJsonPath('amount_cop', 40000);
    }

    public function test_extend_trial_pushes_trial_ends_at_forward(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create(['trial_ends_at' => now()->addDays(3)]);
        $originalTrialEnd = $business->trial_ends_at;

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/superadmin/businesses/{$business->id}/extend-trial", ['days' => 10])
            ->assertOk();

        $business->refresh();
        $this->assertEquals($originalTrialEnd->addDays(10)->toDateString(), $business->trial_ends_at->toDateString());
    }

    public function test_toggle_flips_active_state(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create(['active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/superadmin/businesses/{$business->id}/toggle")
            ->assertOk()
            ->assertJsonPath('active', false);
    }

    public function test_destroy_deactivates_all_users_and_soft_deletes_the_business(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id, 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/superadmin/businesses/{$business->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('businesses', ['id' => $business->id]);
        $this->assertFalse($employee->fresh()->is_active);
    }

    public function test_manual_subscription_payment_can_be_recorded_and_deleted(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create(['paid_until' => now()->addDays(30)]);

        $store = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/superadmin/businesses/{$business->id}/subscription-payments", [
            'amount_cop' => 65000,
            'period_label' => 'Retroactivo',
            'paid_at' => now()->toDateString(),
        ]);
        $store->assertCreated();

        $payment = SaasSubscriptionPayment::first();
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/superadmin/businesses/{$business->id}/subscription-payments/{$payment->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('saas_subscription_payments', ['id' => $payment->id]);
    }

    public function test_config_endpoint_normalizes_flags_against_the_plan_defaults(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create(['subscription_plan' => 'basic']);

        $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/superadmin/businesses/{$business->id}/config", [
            'subscription_plan' => 'full',
            'feature_flags' => ['open_tabs' => false],
        ]);

        $response->assertOk()
            ->assertJsonPath('feature_flags.open_tabs', false)
            // clave que no se mando explicitamente: se completa con el default del plan full.
            ->assertJsonPath('feature_flags.services', true);
    }
}
