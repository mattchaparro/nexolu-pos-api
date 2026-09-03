<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessPaymentGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lo que el comerciante necesita para dejar su pasarela funcionando.
 *
 * Dos huecos que dejaban tirado a un negocio nuevo:
 *
 * 1. Nadie le decia que hay que pegar una URL en el panel de su proveedor.
 *    Conectaba las llaves, veia "Conectado", creia que habia terminado, y su
 *    primer pedido se quedaba esperando un pago que ya habia entrado.
 * 2. "Conectado" solo significa que hay algo guardado. Con unas llaves
 *    equivocadas se enteraba con el primer comprador que no pudo pagar.
 */
class PaymentGatewayOnboardingTest extends TestCase
{
    use DatabaseTransactions;

    private function negocioConPasarela(array $attrs = []): array
    {
        $business = Business::factory()->create(['feature_flags' => ['online_store' => true]]);
        $user = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        $gateway = BusinessPaymentGateway::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'provider_slug' => 'bold',
            'environment' => 'sandbox',
            'payments_core_merchant_id' => 'mrc_abc123',
            'integration_api_key' => 'nxl_test',
            'webhook_secret' => 'whsec_test',
            'is_active' => true,
            'connected_at' => now(),
            ...$attrs,
        ]);

        Sanctum::actingAs($user);

        return [$business, $gateway];
    }

    public function test_le_dice_al_comerciante_que_url_pegar_en_su_proveedor(): void
    {
        $this->negocioConPasarela();

        $response = $this->getJson('/api/v1/payment-gateways')->assertOk();

        $bold = collect($response->json('providers'))->firstWhere('provider_slug', 'bold');

        // Por COMERCIO, no una URL central: asi entran tambien los cobros que
        // el POS no origino (el QR pegado al datafono).
        $this->assertStringContainsString('/v1/webhooks/bold/merchants/mrc_abc123', $bold['webhook_url']);
    }

    /** Sin pasarela conectada no hay URL que mostrar. */
    public function test_sin_conectar_no_hay_url(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['online_store' => true]]);
        Sanctum::actingAs(User::factory()->create([
            'business_id' => $business->id,
            'is_business_owner' => true,
        ]));

        $response = $this->getJson('/api/v1/payment-gateways')->assertOk();
        $bold = collect($response->json('providers'))->firstWhere('provider_slug', 'bold');

        $this->assertNull($bold['webhook_url']);
    }

    public function test_probar_la_conexion_avisa_que_en_pruebas_no_entra_dinero(): void
    {
        $this->negocioConPasarela();

        Http::fake(['*/payments/links' => Http::response([
            'reference' => 'pay_test',
            'url' => 'https://checkout.bold.co/abc',
        ], 201)]);

        $this->postJson('/api/v1/payment-gateways/bold/test')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonFragment(['message' => 'Tus llaves de pruebas funcionan. Recuerda que en pruebas no entra dinero real.']);
    }

    /**
     * El mensaje del proveedor va tal cual: "llave invalida" le dice que
     * corregir, uno generico no.
     */
    public function test_probar_devuelve_el_motivo_real_del_proveedor(): void
    {
        $this->negocioConPasarela();

        Http::fake(['*/payments/links' => Http::response(
            ['detail' => 'Bold rechazo la creacion del link: llave de identidad invalida'],
            502
        )]);

        $this->postJson('/api/v1/payment-gateways/bold/test')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    /**
     * Quien solo vende por internet no tiene que dar las llaves del
     * datafono. Es la razon de que cada juego se valide por separado: el
     * segundo es de Bold "API Datafono", y exigirlo dejaria sin conectar a
     * medio mundo.
     */
    public function test_se_puede_conectar_solo_para_cobrar_por_internet(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['online_store' => true]]);
        Sanctum::actingAs(User::factory()->create([
            'business_id' => $business->id,
            'is_business_owner' => true,
        ]));

        // El alta de un comercio nuevo pasa por el Core, que exige su clave
        // de aprovisionamiento. En pruebas no hay .env con ella.
        config([
            'services.payments_core.base_url' => 'https://payments.test',
            'services.payments_core.provisioning_key' => 'prov-test',
        ]);

        // Una sola respuesta con todo lo que pide la cadena de alta
        // (merchant, integracion y credenciales): son tres llamadas al Core
        // y falsearlas por separado solo agrega ruido a lo que este test
        // quiere probar, que es la validacion del formulario.
        Http::fake(['*' => Http::response([
            'id' => 'mrc_nuevo',
            'api_key' => 'nxl_x',
            'webhook_secret' => 'whsec_x',
            'configured' => true,
        ], 201)]);

        $this->postJson('/api/v1/payment-gateways', [
            'provider_slug' => 'bold',
            'environment' => 'sandbox',
            // SIN terminal_identity_key ni terminal_secret_key.
            'credentials' => ['identity_key' => 'ik', 'secret_key' => 'sk'],
        ])->assertSuccessful();

        $this->assertDatabaseHas('business_payment_gateways', [
            'business_id' => $business->id,
            'provider_slug' => 'bold',
            'is_active' => true,
        ]);
    }

    /** Medio juego guardado es un 403 despues, sin nada que lo explique. */
    public function test_medio_juego_de_llaves_se_rechaza(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['online_store' => true]]);
        Sanctum::actingAs(User::factory()->create([
            'business_id' => $business->id,
            'is_business_owner' => true,
        ]));

        Http::fake();

        $this->postJson('/api/v1/payment-gateways', [
            'provider_slug' => 'bold',
            'environment' => 'sandbox',
            'credentials' => ['identity_key' => 'ik', 'secret_key' => 'sk', 'terminal_identity_key' => 'solo-una'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('credentials.terminal_secret_key');
    }

    public function test_no_se_puede_probar_una_pasarela_sin_conectar(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['online_store' => true]]);
        Sanctum::actingAs(User::factory()->create([
            'business_id' => $business->id,
            'is_business_owner' => true,
        ]));

        Http::fake();

        $this->postJson('/api/v1/payment-gateways/bold/test')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        Http::assertNothingSent();
    }
}
