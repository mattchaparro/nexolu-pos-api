<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\SubscriptionCheckoutOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cubre POST /v1/subscription/checkout/{reference}/charge (API directa via
 * Nexolu Payments Core, flow="api") - ver docs/PLAN_METODOS_PAGO_ALTERNOS.md.
 * No prueba el Core en si (eso lo cubre su propia suite pytest), ni que
 * Wompi acepte cada payload - solo que el POS valida, scoped por negocio, y
 * reenvia el payment_method tal cual al Core.
 */
class SubscriptionChargeTest extends TestCase
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

    public function test_charge_with_card_reaches_payments_core_and_returns_its_ack(): void
    {
        Http::fake([
            'payments-core.test/v1/payments/intents/*/charge' => Http::response([
                'transaction_id' => 'core-tx-1',
                'reference' => 'ignored-here',
                'status' => 'pending',
                'provider_transaction_id' => 'wompi-tx-1',
                'provider_status' => 'PENDING',
                'redirect_url' => null,
            ], 200),
        ]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $order = SubscriptionCheckoutOrder::factory()->for($business)->create(['status' => 'pending']);

        $response = $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/subscription/checkout/{$order->order_key}/charge",
            ['payment_method' => ['type' => 'CARD', 'token' => 'tok_test_1', 'installments' => 1]],
        );

        $response->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('provider_transaction_id', 'wompi-tx-1');

        Http::assertSent(function ($request) use ($order) {
            return $request->url() === "http://payments-core.test/v1/payments/intents/{$order->order_key}/charge"
                && $request->hasHeader('Authorization', 'Bearer test-payments-core-key')
                && $request['payment_method']['type'] === 'CARD'
                && $request['payment_method']['token'] === 'tok_test_1';
        });
    }

    public function test_charge_with_nequi_forwards_the_phone_number(): void
    {
        Http::fake([
            'payments-core.test/v1/payments/intents/*/charge' => Http::response(['status' => 'pending'], 200),
        ]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $order = SubscriptionCheckoutOrder::factory()->for($business)->create(['status' => 'pending']);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/subscription/checkout/{$order->order_key}/charge",
            ['payment_method' => ['type' => 'NEQUI', 'phone_number' => '3107654321']],
        )->assertOk();

        Http::assertSent(fn ($request) => $request['payment_method']['phone_number'] === '3107654321');
    }

    public function test_charge_with_pse_forwards_the_payer_and_bank_data(): void
    {
        Http::fake([
            'payments-core.test/v1/payments/intents/*/charge' => Http::response([
                'status' => 'pending',
                'redirect_url' => 'https://sandbox.wompi.co/some-pse-redirect',
            ], 200),
        ]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $order = SubscriptionCheckoutOrder::factory()->for($business)->create(['status' => 'pending']);

        $response = $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/subscription/checkout/{$order->order_key}/charge",
            ['payment_method' => [
                'type' => 'PSE',
                'user_type' => 0,
                'user_legal_id_type' => 'CC',
                'user_legal_id' => '1099888777',
                'financial_institution_code' => '1',
                'customer_full_name' => 'Cliente De Prueba',
                'customer_phone_number' => '3107654321',
                'payment_description' => 'Suscripcion Nexolu',
            ]],
        );

        $response->assertOk()->assertJsonPath('redirect_url', 'https://sandbox.wompi.co/some-pse-redirect');

        Http::assertSent(fn ($request) => $request['payment_method']['financial_institution_code'] === '1'
            && $request['payment_method']['user_legal_id'] === '1099888777');
    }

    public function test_charge_rejects_a_reference_from_another_business(): void
    {
        Http::fake();

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $otherOrder = SubscriptionCheckoutOrder::factory()->for(Business::factory())->create(['status' => 'pending']);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/subscription/checkout/{$otherOrder->order_key}/charge",
            ['payment_method' => ['type' => 'CARD', 'token' => 'tok_test_1']],
        )->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_charge_rejects_an_order_that_is_no_longer_pending(): void
    {
        Http::fake();

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $order = SubscriptionCheckoutOrder::factory()->for($business)->create(['status' => 'confirmed']);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/subscription/checkout/{$order->order_key}/charge",
            ['payment_method' => ['type' => 'CARD', 'token' => 'tok_test_1']],
        )->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_charge_returns_502_when_payments_core_rejects_it(): void
    {
        Http::fake([
            'payments-core.test/v1/payments/intents/*/charge' => Http::response(
                ['error' => 'Wompi rechazo la creacion de la transaccion: token invalido'],
                502,
            ),
        ]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $order = SubscriptionCheckoutOrder::factory()->for($business)->create(['status' => 'pending']);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/subscription/checkout/{$order->order_key}/charge",
            ['payment_method' => ['type' => 'CARD', 'token' => 'tok_expired']],
        )->assertStatus(502);
    }

    /**
     * @dataProvider missingRequiredFieldsPerType
     */
    public function test_charge_validates_required_fields_per_payment_method_type(array $paymentMethod): void
    {
        Http::fake();

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $order = SubscriptionCheckoutOrder::factory()->for($business)->create(['status' => 'pending']);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/subscription/checkout/{$order->order_key}/charge",
            ['payment_method' => $paymentMethod],
        )->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function missingRequiredFieldsPerType(): array
    {
        return [
            'card without token' => [['type' => 'CARD']],
            'nequi without phone_number' => [['type' => 'NEQUI']],
            'nequi with an invalid phone_number' => [['type' => 'NEQUI', 'phone_number' => '123']],
            'pse without payer data' => [['type' => 'PSE', 'financial_institution_code' => '1']],
            'bancolombia_transfer without payment_description' => [['type' => 'BANCOLOMBIA_TRANSFER']],
            'unknown type' => [['type' => 'DAVIPLATA']],
        ];
    }
}
