<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiMessagePackCheckoutOrder;
use App\Models\AiUsageDaily;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cupo de mensajes de IA (card de Ajustes) + checkout self-serve de un
 * paquete adicional, mismo patron que SubscriptionTest: no prueba Nexolu
 * Payments Core en si, solo que el POS crea la orden pendiente, arma el
 * intent correctamente y maneja bien la respuesta.
 */
class AiMessagePackTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.payments_core.api_key' => 'test-payments-core-key',
            'services.payments_core.base_url' => 'http://payments-core.test',
            'ai.addon.monthly_included_messages' => 300,
            'ai.addon.pack_size' => 1000,
            'ai.addon.pack_price_cop' => 15000,
        ]);
    }

    public function test_state_reports_the_quota_and_pack_balance(): void
    {
        $business = Business::factory()->create(['ai_message_pack_balance' => 200]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');
        AiUsageDaily::factory()->create(['business_id' => $business->id, 'date' => now()->toDateString(), 'messages_count' => 50]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/ai/message-packs/state');

        $response->assertOk()
            ->assertJsonPath('monthly_quota', 300)
            ->assertJsonPath('consumed_this_month', 50)
            ->assertJsonPath('remaining_quota', 250)
            ->assertJsonPath('pack_balance', 200)
            ->assertJsonPath('pack_size', 1000)
            ->assertJsonPath('pack_price_cop', 15000)
            ->assertJsonPath('is_admin', true);
    }

    public function test_initiating_checkout_creates_a_pending_order_and_calls_payments_core(): void
    {
        Http::fake([
            'payments-core.test/*' => Http::response([
                'transaction_id' => 'core-tx-1',
                'provider' => 'wompi',
                'status' => 'pending',
                'checkout' => [
                    'public_key' => 'pub_test',
                    'amount_in_cents' => 1500000,
                    'reference' => 'NEXPACK-1-xxx',
                    'integrity_signature' => 'sig',
                ],
            ], 201),
        ]);

        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id, 'email' => 'admin@nexolu.co', 'name' => 'Admin']);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ai/message-packs/checkout', [
            'redirect_url' => 'https://pos.nexolu.co/ajustes?paid=1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('amount_cop', 15000)
            ->assertJsonPath('checkout.public_key', 'pub_test');

        $reference = $response->json('order_key');
        $this->assertNotEmpty($reference);

        $this->assertDatabaseHas('ai_message_pack_checkout_orders', [
            'business_id' => $business->id,
            'order_key' => $reference,
            'messages' => 1000,
            'price_cop' => 15000,
            'status' => 'pending',
            'provider' => 'wompi',
            'created_by_user_id' => $admin->id,
        ]);

        Http::assertSent(function ($request) use ($reference, $business) {
            return $request->url() === 'http://payments-core.test/v1/payments/intents'
                && $request['reference'] === $reference
                && $request['amount_cop'] === 15000
                && $request['customer']['email'] === 'admin@nexolu.co'
                && $request['metadata']['business_id'] === $business->id
                && $request['metadata']['ai_message_pack_messages'] === 1000;
        });
    }

    public function test_initiating_checkout_deletes_the_order_when_payments_core_is_unreachable(): void
    {
        Http::fake(['payments-core.test/*' => Http::response(['error' => 'no active credentials'], 503)]);

        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ai/message-packs/checkout', [
            'redirect_url' => 'https://pos.nexolu.co/ajustes?paid=1',
        ])->assertStatus(502);

        $this->assertDatabaseCount('ai_message_pack_checkout_orders', 0);
    }

    public function test_checkout_status_returns_the_orders_current_state(): void
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $order = AiMessagePackCheckoutOrder::factory()->for($business)->create([
            'status' => 'confirmed',
            'messages' => 1000,
            'price_cop' => 15000,
            'confirmed_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/ai/message-packs/checkout/{$order->order_key}");

        $response->assertOk()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('amount_cop', 15000)
            ->assertJsonPath('messages', 1000);
    }

    public function test_checkout_status_is_scoped_to_the_authenticated_users_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $order = AiMessagePackCheckoutOrder::factory()->for($otherBusiness)->create();

        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/ai/message-packs/checkout/{$order->order_key}")
            ->assertStatus(404);
    }
}
