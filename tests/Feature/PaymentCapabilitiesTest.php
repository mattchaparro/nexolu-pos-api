<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessPaymentGateway;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use App\Support\PaymentCapabilities;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Las credenciales de pago se piden por CAPACIDAD, no por proveedor.
 *
 * Bold emite dos juegos distintos y no intercambiables: uno para el boton de
 * pagos (cobrar por internet) y otro para la API de datafono (cobrar en el
 * mostrador). Consultar datafonos con la llave del boton devuelve 403 -- se
 * descubrio probando contra una cuenta real, no leyendo la documentacion.
 *
 * Y no toda capacidad aplica a todo negocio: cobrar por internet sin tienda
 * online no significa nada, mientras que el datafono es del mostrador y le
 * sirve a cualquiera.
 */
class PaymentCapabilitiesTest extends TestCase
{
    use DatabaseTransactions;

    private function business(bool $withStore): Business
    {
        return Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => $withStore],
        ]);
    }

    private function owner(Business $business): User
    {
        $user = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_bold_declara_dos_juegos_de_llaves_distintos(): void
    {
        $online = PaymentCapabilities::fieldsFor(BusinessPaymentGateway::PROVIDER_BOLD, PaymentCapabilities::ONLINE);
        $terminal = PaymentCapabilities::fieldsFor(BusinessPaymentGateway::PROVIDER_BOLD, PaymentCapabilities::TERMINAL);

        $this->assertNotEmpty($online);
        $this->assertNotEmpty($terminal);

        // Lo importante: NINGUNA llave se comparte entre las dos capacidades.
        $this->assertSame([], array_intersect($online, $terminal));
    }

    /** Wompi no cobra contra datafono: no debe ofrecer esa capacidad. */
    public function test_wompi_no_ofrece_datafono(): void
    {
        $this->assertSame(
            [PaymentCapabilities::ONLINE],
            PaymentCapabilities::capabilitiesOf(BusinessPaymentGateway::PROVIDER_WOMPI),
        );
    }

    /**
     * La regla de producto: pedirle las llaves del boton de pagos a quien no
     * tiene tienda es pedirle credenciales para algo que no puede usar.
     */
    public function test_sin_tienda_online_no_se_ofrece_cobrar_por_internet(): void
    {
        $business = $this->business(withStore: false);

        $response = $this->actingAs($this->owner($business), 'sanctum')->getJson('/api/v1/payment-gateways');

        $response->assertOk();

        $bold = collect($response->json('providers'))->firstWhere('provider_slug', 'bold');

        $this->assertArrayNotHasKey(PaymentCapabilities::ONLINE, $bold['capabilities']);
        // El datafono si: es del mostrador, no de la tienda.
        $this->assertArrayHasKey(PaymentCapabilities::TERMINAL, $bold['capabilities']);
    }

    public function test_con_tienda_online_se_ofrecen_las_dos(): void
    {
        $business = $this->business(withStore: true);

        $response = $this->actingAs($this->owner($business), 'sanctum')->getJson('/api/v1/payment-gateways');

        $response->assertOk();
        $bold = collect($response->json('providers'))->firstWhere('provider_slug', 'bold');

        $this->assertArrayHasKey(PaymentCapabilities::ONLINE, $bold['capabilities']);
        $this->assertArrayHasKey(PaymentCapabilities::TERMINAL, $bold['capabilities']);
    }

    /**
     * La pantalla ya no ofrece el boton de pagos sin tienda, pero la API no
     * puede confiar en eso: sin este candado, un negocio sin tienda podria
     * mandar esas llaves directo por HTTP.
     */
    public function test_la_api_ignora_credenciales_de_una_capacidad_no_disponible(): void
    {
        $business = $this->business(withStore: false);

        $response = $this->actingAs($this->owner($business), 'sanctum')->postJson('/api/v1/payment-gateways', [
            'provider_slug' => 'bold',
            'environment' => 'sandbox',
            'credentials' => [
                'identity_key' => 'del-boton-de-pagos',
                'secret_key' => 'no-deberia-guardarse',
            ],
        ]);

        // Sin credenciales validas de una capacidad disponible, no se conecta.
        $this->assertNotEquals(201, $response->status());
    }
}
