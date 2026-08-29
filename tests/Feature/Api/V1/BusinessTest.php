<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiChannelIdentity;
use App\Models\Business;
use App\Models\PosPaymentMethod;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_view_their_business(): void
    {
        $business = Business::factory()->create(['name' => 'Cafe Nexolu']);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('id', $business->id)
            ->assertJsonPath('name', 'Cafe Nexolu');
    }

    public function test_business_resource_payment_methods_excludes_disabled_catalog_entries(): void
    {
        // Bug real reportado: el modal de cobro (pago unico, varios medios,
        // dividir) ofrecia CUALQUIER medio del catalogo global, no solo los
        // que el negocio tiene activos - ahi es donde se elige el metodo de
        // una transaccion NUEVA, asi que a diferencia de un reporte
        // historico no hay razon para ofrecer un medio ya desactivado.
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $cash = PosPaymentMethod::factory()->create(['key' => 'cash', 'label' => 'Efectivo', 'sort_order' => 1]);
        $nequi = PosPaymentMethod::factory()->create(['key' => 'nequi', 'label' => 'Nequi', 'sort_order' => 2]);
        $business->posPaymentMethods()->attach([
            $cash->id => ['is_enabled' => true],
            $nequi->id => ['is_enabled' => false],
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk();

        $methodIds = collect($response->json('payment_methods'))->pluck('id');
        $this->assertTrue($methodIds->contains('cash'));
        $this->assertFalse($methodIds->contains('nequi'), 'un medio deshabilitado no deberia ofrecerse para cobrar una transaccion nueva');

        // Pero el label de ese medio debe seguir resolviendo - para mostrar
        // correctamente un pago YA REGISTRADO que lo haya usado antes de
        // desactivarlo (ver PaymentModal.vue, abonos parciales de una venta).
        $this->assertSame('Nequi', $response->json('payment_method_labels.nequi'));
    }

    public function test_business_resource_exposes_computed_can_access_purchases(): void
    {
        $withoutFlags = Business::factory()->create(['feature_flags' => null]);
        $ownerWithout = User::factory()->create(['business_id' => $withoutFlags->id, 'is_business_owner' => true]);

        $this->actingAs($ownerWithout, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('can_access_purchases', true);

        $blocked = Business::factory()->create([
            'feature_flags' => ['inventory' => false, 'inventory_advanced' => false, 'ingredients' => false],
        ]);
        $ownerBlocked = User::factory()->create(['business_id' => $blocked->id, 'is_business_owner' => true]);

        $this->actingAs($ownerBlocked, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('can_access_purchases', false);
    }

    public function test_business_resource_exposes_computed_can_access_services(): void
    {
        $enabled = Business::factory()->create(['feature_flags' => ['services' => true]]);
        $ownerEnabled = User::factory()->create(['business_id' => $enabled->id, 'is_business_owner' => true]);

        $this->actingAs($ownerEnabled, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('can_access_services', true);

        $disabled = Business::factory()->create(['feature_flags' => ['services' => false]]);
        $ownerDisabled = User::factory()->create(['business_id' => $disabled->id, 'is_business_owner' => true]);

        $this->actingAs($ownerDisabled, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('can_access_services', false);
    }

    public function test_business_resource_exposes_computed_can_access_layaways(): void
    {
        $enabled = Business::factory()->create(['feature_flags' => ['layaway' => true]]);
        $ownerEnabled = User::factory()->create(['business_id' => $enabled->id, 'is_business_owner' => true]);

        $this->actingAs($ownerEnabled, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('can_access_layaways', true);

        $disabled = Business::factory()->create(['feature_flags' => ['layaway' => false]]);
        $ownerDisabled = User::factory()->create(['business_id' => $disabled->id, 'is_business_owner' => true]);

        $this->actingAs($ownerDisabled, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('can_access_layaways', false);
    }

    public function test_business_resource_exposes_the_full_resolved_features_map(): void
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'basic',
            'feature_flags' => BusinessFeaturePresets::basic(),
        ]);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/business')->assertOk();

        $resolved = $response->json('resolved_features');
        $this->assertCount(22, $resolved);
        // Basico: encendidas por defecto.
        $this->assertTrue($resolved['inventory']);
        $this->assertTrue($resolved['expenses']);
        // Basico: apagadas por defecto (exclusivas de Full).
        $this->assertFalse($resolved['open_tabs']);
        $this->assertFalse($resolved['services']);
        $this->assertFalse($resolved['scheduling']);
        $this->assertFalse($resolved['clients']);
    }

    public function test_resolved_features_falls_back_to_the_plan_default_for_a_key_missing_from_feature_flags(): void
    {
        // Simula un negocio Basico al que feature_flags le falta una clave
        // (ej. porque se agrego al catalogo despues de crearlo) - la clave
        // ausente debe resolver al default del plan Basico (apagada, ya que
        // 'open_tabs' es exclusiva de Full), NO asumirse encendida.
        $business = Business::factory()->create([
            'subscription_plan' => 'basic',
            'feature_flags' => ['inventory' => true],
        ]);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        $resolved = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->json('resolved_features');

        $this->assertTrue($resolved['inventory']);
        $this->assertFalse($resolved['open_tabs']);
    }

    public function test_resolved_features_enables_everything_only_for_a_business_without_any_flags(): void
    {
        $business = Business::factory()->create(['feature_flags' => null]);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        $resolved = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->json('resolved_features');

        $this->assertTrue($resolved['open_tabs']);
        $this->assertTrue($resolved['clients']);
        $this->assertCount(22, $resolved);
    }

    public function test_owner_can_update_their_business(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/business', ['name' => 'Nuevo Nombre'])
            ->assertOk()
            ->assertJsonPath('name', 'Nuevo Nombre');

        $this->assertSame('Nuevo Nombre', $business->fresh()->name);
    }

    public function test_regular_employee_cannot_update_the_business(): void
    {
        $business = Business::factory()->create(['name' => 'Original']);
        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);

        $this->actingAs($employee, 'sanctum')
            ->putJson('/api/v1/business', ['name' => 'Hackeado'])
            ->assertForbidden();

        $this->assertSame('Original', $business->fresh()->name);
    }

    public function test_owner_can_update_service_orders_settings(): void
    {
        $business = Business::factory()->create([
            'service_orders_show_catalog' => true,
            'service_orders_default_service_name' => null,
        ]);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/business', [
                'service_orders_show_catalog' => false,
                'service_orders_default_service_name' => 'Servicio Tecnico',
            ])
            ->assertOk()
            ->assertJsonPath('service_orders_show_catalog', false)
            ->assertJsonPath('service_orders_default_service_name', 'Servicio Tecnico');

        $business->refresh();
        $this->assertFalse($business->service_orders_show_catalog);
        $this->assertSame('Servicio Tecnico', $business->service_orders_default_service_name);
    }

    public function test_user_without_a_business_gets_not_found(): void
    {
        $user = User::factory()->create(['business_id' => null]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertNotFound();
    }

    public function test_owner_can_restrict_layaway_categories_to_ids_owned_by_their_business(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/business', ['layaway_allowed_category_ids' => [$category->id]])
            ->assertOk()
            ->assertJsonPath('layaway_allowed_category_ids', [$category->id]);

        $this->assertSame([$category->id], $business->fresh()->layaway_allowed_category_ids);
    }

    public function test_owner_cannot_restrict_layaway_categories_to_a_category_from_another_business(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $foreignCategory = ProductCategory::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/business', ['layaway_allowed_category_ids' => [$foreignCategory->id]])
            ->assertStatus(422);
    }

    public function test_owner_can_update_email_branding_which_is_stored_inside_email_config(): void
    {
        $business = Business::factory()->create(['email_config' => ['header_color' => '#000000', 'footer_text' => 'Old']]);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/business', [
                'email_header_color' => '#ff0000',
                'email_footer_text' => 'Gracias por tu compra',
                'email_whatsapp_cta' => true,
            ])
            ->assertOk()
            ->assertJsonPath('email_header_color', '#ff0000')
            ->assertJsonPath('email_footer_text', 'Gracias por tu compra')
            ->assertJsonPath('email_whatsapp_cta', true);

        $business->refresh();
        $this->assertEquals(['header_color' => '#ff0000', 'footer_text' => 'Gracias por tu compra'], $business->email_config);
        $this->assertTrue($business->email_whatsapp_cta);
    }

    public function test_notifications_require_the_saving_user_to_have_whatsapp_linked(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/business/notifications', ['preferences' => ['resumen_diario' => true]])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertNull($business->fresh()->notification_preferences);
    }

    public function test_owner_with_whatsapp_linked_can_save_notification_preferences(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        AiChannelIdentity::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'channel' => AiChannelIdentity::CHANNEL_WHATSAPP,
            'external_id' => '573001234567',
            'verified_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/business/notifications', [
                'preferences' => ['resumen_diario' => true, 'inventario_bajo' => true, 'unknown_key' => true],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $prefs = $business->fresh()->notification_preferences;
        $this->assertTrue($prefs['resumen_diario']);
        $this->assertTrue($prefs['inventario_bajo']);
        $this->assertFalse($prefs['recordatorios']);
        $this->assertArrayNotHasKey('unknown_key', $prefs);
    }

    public function test_owner_can_save_a_custom_hour_for_a_schedulable_notification(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        AiChannelIdentity::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'channel' => AiChannelIdentity::CHANNEL_WHATSAPP,
            'external_id' => '573001234567',
            'verified_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/business/notifications', [
                'preferences' => ['resumen_diario' => true],
                // recordatorios no es schedulable (ver NotificationTypes::SCHEDULABLE)
                // - una hora para el se descarta en vez de guardarse.
                'schedule' => ['resumen_diario' => '22:30', 'recordatorios' => '09:00'],
            ])
            ->assertOk();

        $schedule = $business->fresh()->notification_schedule;
        $this->assertSame('22:30', $schedule['resumen_diario']);
        $this->assertArrayNotHasKey('recordatorios', $schedule);
    }

    public function test_business_resource_resolves_notification_schedule_defaults_when_unset(): void
    {
        $business = Business::factory()->create(['notification_schedule' => ['resumen_diario' => '22:30']]);
        $user = User::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/business')->assertOk();

        // resumen_diario personalizado, inventario_bajo cae al default de
        // la plataforma (ver NotificationTypes::DEFAULT_HOURS) porque este
        // negocio nunca lo toco.
        $response->assertJsonPath('notification_schedule.resumen_diario', '22:30');
        $response->assertJsonPath('notification_schedule.inventario_bajo', '08:00');
    }

    public function test_saving_notifications_rejects_an_invalid_hour_format(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        AiChannelIdentity::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'channel' => AiChannelIdentity::CHANNEL_WHATSAPP,
            'external_id' => '573001234567',
            'verified_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/business/notifications', [
                'preferences' => ['resumen_diario' => true],
                'schedule' => ['resumen_diario' => '10pm'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schedule.resumen_diario']);
    }

    public function test_regular_employee_cannot_update_notifications(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);

        $this->actingAs($employee, 'sanctum')
            ->putJson('/api/v1/business/notifications', ['preferences' => ['resumen_diario' => true]])
            ->assertForbidden();

        $this->assertNull($business->fresh()->notification_preferences);
    }

    public function test_owner_can_clear_the_low_stock_snooze_early(): void
    {
        $business = Business::factory()->create(['low_stock_snoozed_until' => now()->addDays(15)]);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/v1/business/low-stock-snooze')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNull($business->fresh()->low_stock_snoozed_until);
    }

    public function test_regular_employee_cannot_clear_the_low_stock_snooze(): void
    {
        $business = Business::factory()->create(['low_stock_snoozed_until' => now()->addDays(15)]);
        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);

        $this->actingAs($employee, 'sanctum')
            ->deleteJson('/api/v1/business/low-stock-snooze')
            ->assertForbidden();

        $this->assertNotNull($business->fresh()->low_stock_snoozed_until);
    }
}
