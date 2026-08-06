<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cubre el checkout de suscripcion orquestado via Nexolu Payments Core (repo
 * Python aparte): este POS ya no le habla a Wompi directo. No prueba el Core
 * en si (eso lo cubre su propia suite pytest) - solo que el POS crea la
 * orden pendiente, arma el intent correctamente y maneja bien la respuesta.
 */
class SubscriptionTest extends TestCase
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

    public function test_status_reports_the_businesss_subscription_state(): void
    {
        $business = Business::factory()->create([
            'paid_until' => now()->addDays(10),
            'subscription_plan' => 'basic',
            'custom_price_cop' => null,
        ]);
        $user = User::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/subscription/status');

        $response->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('monthly_price_cop', 65000);
    }

    public function test_initiating_checkout_creates_a_pending_order_and_calls_payments_core(): void
    {
        Http::fake([
            'payments-core.test/*' => Http::response([
                'transaction_id' => 'core-tx-1',
                'reference' => 'ignored-here',
                'provider' => 'wompi',
                'status' => 'pending',
                'checkout' => [
                    'public_key' => 'pub_test',
                    'amount_in_cents' => 6500000,
                    'reference' => 'NEX-1-xxx',
                    'integrity_signature' => 'sig',
                ],
            ], 201),
        ]);

        $business = Business::factory()->create(['subscription_plan' => 'basic', 'custom_price_cop' => null]);
        $user = User::factory()->create(['business_id' => $business->id, 'email' => 'admin@nexolu.co', 'name' => 'Admin']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/subscription/checkout', [
            'redirect_url' => 'https://pos.nexolu.co/billing?paid=1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('amount_cop', 65000)
            ->assertJsonPath('checkout.public_key', 'pub_test');

        $reference = $response->json('order_key');
        $this->assertNotEmpty($reference);

        $this->assertDatabaseHas('subscription_checkout_orders', [
            'business_id' => $business->id,
            'order_key' => $reference,
            'amount_cop' => 65000,
            'subscription_days' => 30,
            'status' => 'pending',
            'provider' => 'wompi',
        ]);

        Http::assertSent(function ($request) use ($reference, $business) {
            return $request->url() === 'http://payments-core.test/v1/payments/intents'
                && $request->hasHeader('Authorization', 'Bearer test-payments-core-key')
                && $request['reference'] === $reference
                && $request['amount_cop'] === 65000
                && $request['customer']['email'] === 'admin@nexolu.co'
                && $request['metadata']['business_id'] === $business->id;
        });
    }

    public function test_initiating_checkout_deletes_the_order_when_payments_core_is_unreachable(): void
    {
        Http::fake([
            'payments-core.test/*' => Http::response(['error' => 'integration has no active credentials'], 503),
        ]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/subscription/checkout', [
            'redirect_url' => 'https://pos.nexolu.co/billing?paid=1',
        ])->assertStatus(502);

        $this->assertDatabaseCount('subscription_checkout_orders', 0);
    }

    public function test_initiate_validates_the_redirect_url(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/subscription/checkout', [
            'redirect_url' => 'not-a-url',
        ])->assertStatus(422);
    }
}
