<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiChannelIdentity;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Layaway;
use App\Models\LayawayPayment;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\ServiceOrder;
use App\Models\ServicePayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_today_summary_uses_the_bogota_calendar_day_not_utc(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        // Venta cerrada a las 6pm hora Bogota (13-ago) - 11pm UTC del mismo dia.
        Carbon::setTestNow(Carbon::parse('2026-08-13 18:00:00', 'America/Bogota'));
        Sale::factory()->for($business)->create(['status' => 'closed', 'total' => 50000]);

        // El dueño mira el resumen a las 9pm hora Bogota, mismo dia para el
        // - pero ya son las 2am UTC del dia siguiente. Con
        // config('app.timezone')=UTC (bug real corregido aca),
        // Carbon::today() ya habia rodado a "mañana" en UTC, y la venta de
        // las 6pm quedaba fuera del whereDate('closed_at', $today) del
        // resumen - desaparecia sin que el dueño hubiera cambiado de dia.
        Carbon::setTestNow(Carbon::parse('2026-08-13 21:00:00', 'America/Bogota'));

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk();

        $response->assertJsonPath('today_sales', 50000);
        $response->assertJsonPath('today_count', 1);
    }

    public function test_today_summary_combines_sales_receivables_and_service_payments(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        // Cuenta: venta cerrada normal de hoy.
        Sale::factory()->for($business)->create(['status' => 'closed', 'total' => 50000]);
        // No cuenta: venta a credito (aunque este cerrada).
        Sale::factory()->for($business)->create(['status' => 'closed', 'total' => 20000, 'is_credit' => true]);
        // No cuenta: venta no-revenue.
        Sale::factory()->for($business)->create(['status' => 'closed', 'total' => 15000, 'is_non_revenue' => true]);
        // No cuenta en today_sales/today_count: sigue abierta, pero SI cuenta en open_tabs_total.
        Sale::factory()->for($business)->create(['status' => 'open', 'total' => 30000]);

        // Cuenta: fiado cobrado hoy.
        Receivable::factory()->for($business)->paid()->create(['amount' => 10000]);
        // No cuenta: fiado pendiente (no cobrado).
        Receivable::factory()->for($business)->create(['amount' => 8000, 'balance' => 8000]);

        // Cuenta: pago de servicio de hoy.
        $order = ServiceOrder::factory()->for($business)->create();
        ServicePayment::factory()->for($order, 'order')->create(['business_id' => $business->id, 'amount' => 5000]);

        // Cuenta: abono a apartado de hoy (antes de la correccion, esta
        // fuente de ingreso no se contaba en ningun lado del resumen).
        $layaway = Layaway::factory()->for($business)->create();
        LayawayPayment::factory()->for($layaway, 'layaway')->create(['business_id' => $business->id, 'amount' => 3000]);

        // Cuenta: gasto operacional de hoy.
        Expense::factory()->for($business)->create(['value' => 7000, 'scope' => 'operacional', 'date' => now()->toDateString()]);
        // No cuenta: gasto administrativo (no operacional), aunque sea de hoy.
        Expense::factory()->for($business)->create(['value' => 3000, 'scope' => 'administrativo', 'date' => now()->toDateString()]);
        // No cuenta: gasto operacional pero de otro dia.
        Expense::factory()->for($business)->create(['value' => 9000, 'scope' => 'operacional', 'date' => now()->subDay()->toDateString()]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk();

        // json_encode omite el ".0" de floats sin parte decimal, por eso se
        // comparan como enteros - el valor real ya es float en PHP (ver DashboardService).
        $response->assertJsonPath('today_sales', 68000); // 50000 venta + 10000 fiado + 5000 servicio + 3000 apartado
        $response->assertJsonPath('today_count', 1);
        // Venta, fiado, servicio y apartado por defecto de fabrica son
        // 'cash' (ver SaleFactory/ReceivableFactory/ServicePaymentFactory/
        // LayawayPaymentFactory) - las 4 fuentes entran en el desglose
        // efectivo/transferencia (fix: antes solo entraban ventas y fiados).
        $response->assertJsonPath('today_cash', 68000);
        $response->assertJsonPath('today_transfer', 0);
        $response->assertJsonPath('open_tabs_total', 30000);
        $response->assertJsonPath('receivables_enabled', true);
        $response->assertJsonPath('pending_receivables', 8000);
        $response->assertJsonPath('expenses_enabled', true);
        $response->assertJsonPath('today_expenses', 7000);
    }

    public function test_today_cash_and_transfer_split_sales_paid_with_multiple_methods(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        Sale::factory()->for($business)->create(['status' => 'closed', 'total' => 40000, 'payment_method' => 'cash']);
        Sale::factory()->for($business)->create(['status' => 'closed', 'total' => 25000, 'payment_method' => 'transfer']);
        // No cuenta en cash/transfer (ni en ningun lado del desglose): otro medio de pago.
        Sale::factory()->for($business)->create(['status' => 'closed', 'total' => 15000, 'payment_method' => 'nequi']);
        Receivable::factory()->for($business)->paid()->create(['amount' => 5000, 'payment_method' => 'transfer']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('today_cash', 40000)
            ->assertJsonPath('today_transfer', 30000); // 25000 venta + 5000 fiado
    }

    public function test_today_cash_and_transfer_resolve_the_business_configured_spanish_ids(): void
    {
        // Negocio con ids en espanol (los que usan los negocios nuevos) -
        // antes del fix, 'efectivo'/'transferencia' caian silenciosamente
        // fuera de today_cash/today_transfer porque el codigo asumia
        // literalmente 'cash'/'transfer' (mismo BUG que el legacy nunca
        // corrigio en su DashboardController).
        $business = Business::factory()->create([
            'payment_methods' => [
                ['id' => 'efectivo', 'label' => 'Efectivo'],
                ['id' => 'transferencia', 'label' => 'Transferencia'],
            ],
        ]);
        $user = User::factory()->create(['business_id' => $business->id]);

        Sale::factory()->for($business)->create(['status' => 'closed', 'total' => 40000, 'payment_method' => 'efectivo']);
        Sale::factory()->for($business)->create(['status' => 'closed', 'total' => 25000, 'payment_method' => 'transferencia']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('today_cash', 40000)
            ->assertJsonPath('today_transfer', 25000);
    }

    public function test_disabled_features_zero_out_their_stats(): void
    {
        $business = Business::factory()->create([
            'feature_flags' => ['receivables' => false, 'expenses' => false],
        ]);
        $user = User::factory()->create(['business_id' => $business->id]);

        Receivable::factory()->for($business)->create(['amount' => 8000, 'balance' => 8000]);
        Expense::factory()->for($business)->create(['value' => 7000, 'scope' => 'operacional']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('receivables_enabled', false)
            ->assertJsonPath('pending_receivables', 0)
            ->assertJsonPath('expenses_enabled', false)
            ->assertJsonPath('today_expenses', 0);
    }

    public function test_guest_cannot_access_dashboard_summary(): void
    {
        $this->getJson('/api/v1/dashboard/summary')->assertUnauthorized();
    }

    public function test_summary_reports_null_shortcuts_when_the_user_never_customized_them(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('shortcuts', null);
    }

    public function test_summary_reports_the_users_saved_shortcuts(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'dashboard_shortcuts' => [
                ['route_name' => 'sales.create', 'color' => 'primary'],
                ['route_name' => 'catalog.index', 'color' => 'outline'],
            ],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('shortcuts.0.route_name', 'sales.create')
            ->assertJsonPath('shortcuts.0.color', 'primary')
            ->assertJsonPath('shortcuts.1.route_name', 'catalog.index');
    }

    public function test_a_business_user_can_save_their_own_shortcuts(): void
    {
        // Sin middleware business-admin a proposito: cada usuario (admin o
        // empleado) tiene su propio set de atajos, dashboard_shortcuts es
        // una columna por usuario, no por negocio - ver la nota en
        // routes/api.php.
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('employee');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/dashboard/shortcuts', [
                'shortcuts' => [
                    ['route_name' => 'sales.create', 'color' => 'primary'],
                    ['route_name' => 'open-tabs.index', 'color' => 'outline'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('shortcuts.0.route_name', 'sales.create')
            ->assertJsonPath('shortcuts.1.color', 'outline');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $user->refresh();
        $this->assertSame('sales.create', $user->dashboard_shortcuts[0]['route_name']);
    }

    public function test_saving_shortcuts_rejects_an_invalid_color(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/dashboard/shortcuts', [
                'shortcuts' => [['route_name' => 'sales.create', 'color' => 'purple']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shortcuts.0.color']);
    }

    public function test_guest_cannot_save_dashboard_shortcuts(): void
    {
        $this->putJson('/api/v1/dashboard/shortcuts', ['shortcuts' => []])->assertUnauthorized();
    }

    public function test_whatsapp_onboarding_reports_unlinked_for_a_user_with_ai_chat_access(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/whatsapp-onboarding')
            ->assertOk()
            ->assertJsonPath('linked', false);
    }

    public function test_whatsapp_onboarding_reports_linked_once_the_user_has_a_verified_identity(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        AiChannelIdentity::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'channel' => AiChannelIdentity::CHANNEL_WHATSAPP,
            'external_id' => '573001234567',
            'verified_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/whatsapp-onboarding')
            ->assertOk()
            ->assertJsonPath('linked', true);
    }

    public function test_whatsapp_onboarding_is_null_without_ai_chat_permission(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('employee');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/whatsapp-onboarding')
            ->assertOk()
            ->assertContent('null');
    }

    public function test_whatsapp_onboarding_is_null_when_the_business_blocked_ai_chat(): void
    {
        $business = Business::factory()->create(['ai_chat_blocked' => true]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/whatsapp-onboarding')
            ->assertOk()
            ->assertContent('null');
    }

    public function test_whatsapp_onboarding_is_null_after_being_dismissed(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/dashboard/whatsapp-onboarding/dismiss')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('users', ['id' => $user->id, 'whatsapp_onboarding_dismissed_at' => null]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/whatsapp-onboarding')
            ->assertOk()
            ->assertContent('null');
    }
}
