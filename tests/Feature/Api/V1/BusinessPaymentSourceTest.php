<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessPaymentSource;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cubre el "wallet" de metodos de pago guardados del negocio (tarjeta/Nequi
 * tokenizados para reuso via Nexolu Payments Core) - ver
 * docs/PLAN_METODOS_PAGO_ALTERNOS.md seccion 9. No prueba el Core en si
 * (eso lo cubre su propia suite pytest) - solo que este POS crea la fuente
 * correctamente, la guarda scoped por negocio, y el soft-delete no llama a
 * Wompi (confirmado que "void" no sirve para fuentes normales).
 */
class BusinessPaymentSourceTest extends TestCase
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

    public function test_store_creates_the_source_in_the_core_and_persists_it(): void
    {
        Http::fake([
            'payments-core.test/v1/payments/payment-sources' => Http::response([
                'payment_source_id' => '363489',
                'type' => 'CARD',
                'status' => 'AVAILABLE',
            ], 201),
        ]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id, 'email' => 'admin@nexolu.co']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/payment-sources', [
            'type' => 'CARD',
            'token' => 'tok_test_123',
            'label' => 'Visa •••• 4242',
        ]);

        $response->assertCreated()
            ->assertJsonPath('payment_source_id', '363489')
            ->assertJsonPath('type', 'CARD')
            ->assertJsonPath('label', 'Visa •••• 4242');

        $this->assertDatabaseHas('business_payment_sources', [
            'business_id' => $business->id,
            'payment_source_id' => '363489',
            'type' => 'CARD',
            'status' => 'active',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://payments-core.test/v1/payments/payment-sources'
                && $request->hasHeader('Authorization', 'Bearer test-payments-core-key')
                && $request['type'] === 'CARD'
                && $request['token'] === 'tok_test_123'
                && $request['customer_email'] === 'admin@nexolu.co';
        });
    }

    public function test_store_returns_502_when_the_core_rejects_the_token(): void
    {
        Http::fake([
            'payments-core.test/v1/payments/payment-sources' => Http::response(
                ['error' => 'Wompi rechazo la creacion de la fuente de pago: token invalido'],
                502,
            ),
        ]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/payment-sources', [
            'type' => 'CARD',
            'token' => 'tok_invalido',
            'label' => 'Visa •••• 0000',
        ])->assertStatus(502);

        $this->assertDatabaseCount('business_payment_sources', 0);
    }

    public function test_index_lists_only_active_sources_of_the_authenticated_business(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        BusinessPaymentSource::factory()->for($business)->create(['label' => 'Visa •••• 4242', 'status' => 'active']);
        BusinessPaymentSource::factory()->for($business)->create(['label' => 'Nequi 310•••4321', 'status' => 'removed']);
        BusinessPaymentSource::factory()->for(Business::factory())->create(['status' => 'active']); // otro negocio

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/payment-sources');

        $response->assertOk();
        $sources = $response->json('payment_sources');
        $this->assertCount(1, $sources);
        $this->assertSame('Visa •••• 4242', $sources[0]['label']);
    }

    public function test_destroy_soft_deletes_without_calling_the_core(): void
    {
        Http::fake();

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $source = BusinessPaymentSource::factory()->for($business)->create(['status' => 'active']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/payment-sources/{$source->id}")
            ->assertOk();

        $this->assertDatabaseHas('business_payment_sources', ['id' => $source->id, 'status' => 'removed']);
        // Wompi no permite anular una fuente de pago normal (confirmado en
        // sandbox) - el soft-delete es 100% local, nunca llama al Core.
        Http::assertNothingSent();
    }

    public function test_destroy_rejects_a_source_from_another_business(): void
    {
        Http::fake();

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $otherSource = BusinessPaymentSource::factory()->for(Business::factory())->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/payment-sources/{$otherSource->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('business_payment_sources', ['id' => $otherSource->id, 'status' => 'active']);
    }
}
