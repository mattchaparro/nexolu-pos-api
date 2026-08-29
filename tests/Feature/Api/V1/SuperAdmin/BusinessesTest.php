<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Mail\NewUserCredentialsMail;
use App\Models\Business;
use App\Models\LogAction;
use App\Models\Product;
use App\Models\SaasSubscriptionPayment;
use App\Models\Sale;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
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

    /**
     * A diferencia del wizard publico (que solo puede APAGAR lo que el plan
     * trae), el panel puede darle una funcion suelta a un negocio Basico -
     * es lo que se pacta en una llamada de ventas.
     */
    public function test_superadmin_can_create_a_business_with_features_outside_its_plan(): void
    {
        $admin = $this->superadmin();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/businesses', [
            'business_name' => 'Boutique Basica Con Tienda',
            'owner_name' => 'Dueña Boutique',
            'email' => 'boutique@example.com',
            'password' => 'secret123',
            'plan' => 'basic',
            'feature_flags' => ['online_store' => true, 'variants' => true, 'expenses' => false],
        ])->assertCreated();

        $business = Business::find($response->json('id'));
        $this->assertSame('basic', $business->subscription_plan);
        $this->assertTrue($business->hasFeature('online_store'));
        $this->assertTrue($business->hasFeature('variants'));
        $this->assertFalse($business->hasFeature('expenses'));
        // Las claves que no se tocaron siguen valiendo lo del plan.
        $this->assertTrue($business->hasFeature('inventory'));
    }

    public function test_superadmin_can_create_a_business_already_paying(): void
    {
        $admin = $this->superadmin();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/businesses', [
            'business_name' => 'Negocio Que Entra Pagando',
            'owner_name' => 'Dueño Pago',
            'email' => 'pago@example.com',
            'password' => 'secret123',
            'plan' => 'full',
            'trial_days' => 0,
            'activate_days' => 30,
            'amount_cop' => 120000,
            'custom_price_cop' => 120000,
            'notes' => 'Cerrado por llamada.',
        ])->assertCreated();

        $business = Business::find($response->json('id'));
        $this->assertNotNull($business->paid_until);
        $this->assertTrue($business->paid_until->isFuture());
        $this->assertSame(120000, (int) $business->custom_price_cop);

        $this->assertDatabaseHas('saas_subscription_payments', [
            'business_id' => $business->id,
            'amount_cop' => 120000,
            'days_granted' => 30,
            'notes' => 'Cerrado por llamada.',
        ]);
    }

    public function test_superadmin_can_choose_a_custom_trial_length(): void
    {
        $admin = $this->superadmin();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/businesses', [
            'business_name' => 'Negocio Prueba Larga',
            'owner_name' => 'Dueño Prueba',
            'email' => 'prueba@example.com',
            'password' => 'secret123',
            'trial_days' => 45,
        ])->assertCreated();

        $business = Business::find($response->json('id'));
        $this->assertSame(45, (int) now()->startOfDay()->diffInDays($business->trial_ends_at->startOfDay()));
    }

    public function test_superadmin_can_mail_the_owner_their_credentials_on_creation(): void
    {
        Mail::fake();
        $admin = $this->superadmin();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/businesses', [
            'business_name' => 'Negocio Con Credenciales',
            'owner_name' => 'Dueño Correo',
            'email' => 'credenciales@example.com',
            'password' => 'secret123',
            'send_credentials' => true,
        ])->assertCreated();

        Mail::assertSent(NewUserCredentialsMail::class, fn ($mail) => $mail->hasTo('credenciales@example.com')
            && $mail->plainPassword === 'secret123');
    }

    public function test_creating_a_business_without_asking_does_not_mail_credentials(): void
    {
        Mail::fake();
        $admin = $this->superadmin();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/businesses', [
            'business_name' => 'Negocio Sin Credenciales',
            'owner_name' => 'Dueño Callado',
            'email' => 'sincredenciales@example.com',
            'password' => 'secret123',
        ])->assertCreated();

        Mail::assertNotSent(NewUserCredentialsMail::class);
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

    public function test_can_filter_by_days_without_activity(): void
    {
        $admin = $this->superadmin();

        $recent = Business::factory()->create(['name' => 'Negocio Activo']);
        LogAction::create(['action' => 'x', 'business_id' => $recent->id])
            ->forceFill(['created_at' => now()->subDays(2)])->save();

        $stale = Business::factory()->create(['name' => 'Negocio Dormido']);
        LogAction::create(['action' => 'x', 'business_id' => $stale->id])
            ->forceFill(['created_at' => now()->subDays(45)])->save();

        $never = Business::factory()->create(['name' => 'Negocio Nunca Activo']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/businesses?inactive_days=30');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Negocio Dormido', $names);
        $this->assertContains('Negocio Nunca Activo', $names);
        $this->assertNotContains('Negocio Activo', $names);
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

    public function test_toggle_ai_chat_block_flips_the_flag(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create(['ai_chat_blocked' => false]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/superadmin/businesses/{$business->id}/ai-chat-block")
            ->assertOk()
            ->assertJsonPath('ai_chat_blocked', true);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/superadmin/businesses/{$business->id}/ai-chat-block")
            ->assertOk()
            ->assertJsonPath('ai_chat_blocked', false);
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

    /**
     * Apagar `variants` con variantes ya creadas rompia el catalogo en
     * silencio: effectiveStock() vuelve a leer products.stock (fantasma,
     * siempre 0 para esos productos) y todo el catalogo con variantes queda
     * "sin stock" e invendible. Ver
     * SuperAdminBusinessService::assertVariantsCanBeDisabled().
     */
    public function test_config_endpoint_refuses_to_disable_variants_while_the_business_has_variants(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create(['subscription_plan' => 'full', 'feature_flags' => ['variants' => true]]);
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 0]);
        $product->variants()->create(['business_id' => $business->id, 'sku' => 'SA-1', 'price' => 1000, 'stock' => 40]);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/superadmin/businesses/{$business->id}/config", [
            'subscription_plan' => 'full',
            'feature_flags' => ['variants' => false],
        ])->assertStatus(422)->assertJsonValidationErrors('feature_flags.variants');

        $this->assertTrue($business->fresh()->hasFeature('variants'));
    }

    /**
     * Mismo riesgo por la otra puerta: bajar de plan full a basic con la
     * clave 'variants' AUSENTE del JSON del negocio (el caso de todos los
     * negocios creados antes de que existiera la bandera) hacia ganar al
     * default del plan basico (false) sobre la ausencia.
     */
    public function test_downgrading_the_plan_cannot_silently_disable_variants_in_use(): void
    {
        $admin = $this->superadmin();
        // feature_flags sin la clave 'variants': hasFeature() la resuelve por
        // el default del plan full (true), asi que el negocio pudo crearlas.
        $business = Business::factory()->create(['subscription_plan' => 'full', 'feature_flags' => ['inventory' => true]]);
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 0]);
        $product->variants()->create(['business_id' => $business->id, 'sku' => 'SA-2', 'price' => 1000, 'stock' => 12]);
        $this->assertTrue($business->hasFeature('variants'));

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/superadmin/businesses/{$business->id}/plan", ['plan' => 'basic'])
            ->assertStatus(422);

        $this->assertTrue($business->fresh()->hasFeature('variants'));
    }
}
