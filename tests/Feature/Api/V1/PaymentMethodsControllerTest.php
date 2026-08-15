<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cubre el catalogo de metodos de pago / bancos PSE proxeado desde Nexolu
 * Payments Core - ver docs/PLAN_METODOS_PAGO_ALTERNOS.md secciones 4.5/5.4.
 * Solo prueba el proxy (auth, forwarding, manejo de error) - el filtrado
 * real de accepted_payment_methods contra lo que el Core sabe orquestar
 * vive del lado del Core (su propia suite pytest).
 */
class PaymentMethodsControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.payments_core.api_key' => 'test-payments-core-key',
            'services.payments_core.base_url' => 'http://payments-core.test',
        ]);
    }

    public function test_index_returns_the_accepted_payment_methods_from_the_core(): void
    {
        Http::fake([
            'payments-core.test/v1/payments/payment-methods' => Http::response([
                'provider' => 'wompi',
                'accepted_payment_methods' => ['CARD', 'NEQUI', 'PSE', 'BANCOLOMBIA_TRANSFER'],
            ], 200),
        ]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/payment-methods');

        $response->assertOk()
            ->assertJsonPath('provider', 'wompi')
            ->assertJsonPath('accepted_payment_methods', ['CARD', 'NEQUI', 'PSE', 'BANCOLOMBIA_TRANSFER']);

        Http::assertSent(fn ($request) => $request->url() === 'http://payments-core.test/v1/payments/payment-methods'
            && $request->hasHeader('Authorization', 'Bearer test-payments-core-key'));
    }

    public function test_index_returns_502_when_the_core_is_unreachable(): void
    {
        Http::fake([
            'payments-core.test/v1/payments/payment-methods' => Http::response(['error' => 'not configured'], 503),
        ]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/payment-methods')->assertStatus(502);
    }

    public function test_pse_financial_institutions_returns_the_bank_list_from_the_core(): void
    {
        Http::fake([
            'payments-core.test/v1/payments/pse/financial-institutions' => Http::response([
                'financial_institutions' => [
                    ['code' => '1', 'name' => 'Banco que aprueba'],
                    ['code' => '2', 'name' => 'Banco que declina'],
                ],
            ], 200),
        ]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/pse/financial-institutions');

        $response->assertOk()
            ->assertJsonPath('financial_institutions.0.code', '1')
            ->assertJsonPath('financial_institutions.0.name', 'Banco que aprueba');
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/payment-methods')->assertStatus(401);
        $this->getJson('/api/v1/pse/financial-institutions')->assertStatus(401);
    }
}
