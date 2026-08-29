<?php

namespace Tests\Feature\Api;

use App\Mail\SubscriptionPaymentResultMail;
use App\Mail\SubscriptionPaymentSuperadminNoticeMail;
use App\Models\AiMessagePackCheckoutOrder;
use App\Models\Business;
use App\Models\BusinessPaymentGateway;
use App\Models\Order;
use App\Models\Product;
use App\Models\SaasSubscriptionPayment;
use App\Models\Sale;
use App\Models\SubscriptionCheckoutOrder;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

/**
 * Cubre el webhook publico y firmado que Nexolu Payments Core (repo Python
 * aparte) usa para notificar el resultado de un cobro. La fuente de verdad
 * de un pago es este webhook, nunca la respuesta del navegador - por eso se
 * cubre a fondo la verificacion de firma y la idempotencia contra
 * reintentos (el Core reintenta hasta 3 veces si no responde 2xx).
 */
class PaymentsCoreWebhookTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.payments_core.webhook_secret' => self::SECRET]);
    }

    private function sign(string $body, string $timestamp, ?string $secret = null): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? self::SECRET);
    }

    private function postSigned(array $payload, ?string $secret = null): TestResponse
    {
        $body = json_encode($payload);
        $timestamp = (string) now()->timestamp;

        return $this->call(
            'POST',
            '/api/webhooks/payments-core',
            [],
            [],
            [],
            [
                'HTTP_X-Nexolu-Timestamp' => $timestamp,
                'HTTP_X-Nexolu-Signature' => $this->sign($body, $timestamp, $secret),
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );
    }

    // -----------------------------------------------------------------
    // Pedidos de la tienda online: los firma la pasarela DEL NEGOCIO, no
    // la integracion de Nexolu.
    // -----------------------------------------------------------------

    /** @return array{0: Order, 1: string} */
    private function onlineOrderWithGateway(string $secret = 'secreto-del-negocio'): array
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'full',
            'feature_flags' => [...BusinessFeaturePresets::full(), 'online_store' => true],
            'payment_methods' => [['id' => 'cash', 'label' => 'Efectivo'], ['id' => 'card', 'label' => 'Tarjeta']],
        ]);
        User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        BusinessPaymentGateway::create([
            'business_id' => $business->id,
            'provider_slug' => 'bold',
            'payments_core_merchant_id' => 'mch_1',
            'integration_api_key' => 'nxl_key',
            'webhook_secret' => $secret,
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'is_published' => true,
            'is_active' => true,
            'is_service' => false,
            'track_stock' => true,
            'stock' => 5,
            'price' => 20000,
        ]);

        $order = Order::create([
            'business_id' => $business->id,
            'number' => 1,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 20000,
            'total' => 20000,
            'customer_name' => 'Ana',
            'customer_phone' => '3001234567',
            'is_pickup' => true,
            'public_token' => 'tok'.uniqid(),
            'payment_provider' => 'bold',
            'payment_reference' => 'pay_'.uniqid(),
        ]);
        $order->items()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal' => 20000,
        ]);

        return [$order, $secret];
    }

    public function test_an_approved_online_payment_creates_the_sale(): void
    {
        [$order, $secret] = $this->onlineOrderWithGateway();

        $this->postSigned(
            ['event' => 'payment.approved', 'reference' => $order->payment_reference],
            $secret,
        )->assertOk();

        $order->refresh();
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
        $this->assertNotNull($order->sale_id, 'La venta nace cuando entra la plata');
        $this->assertNotNull($order->paid_at);
    }

    /**
     * Lo que de verdad protege esto: un evento firmado con el secreto de
     * Nexolu (o de otro negocio) no puede confirmar el pedido de este.
     */
    public function test_an_online_payment_signed_with_the_wrong_secret_is_rejected(): void
    {
        [$order] = $this->onlineOrderWithGateway();

        $this->postSigned(
            ['event' => 'payment.approved', 'reference' => $order->payment_reference],
            self::SECRET,
        )->assertStatus(401);

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertNull($order->fresh()->sale_id);
    }

    public function test_repeating_the_approved_event_does_not_create_a_second_sale(): void
    {
        [$order, $secret] = $this->onlineOrderWithGateway();
        $payload = ['event' => 'payment.approved', 'reference' => $order->payment_reference];

        $this->postSigned($payload, $secret)->assertOk();
        $firstSaleId = $order->fresh()->sale_id;

        $this->postSigned($payload, $secret)->assertOk();

        $this->assertSame($firstSaleId, $order->fresh()->sale_id, 'Un reintento del Core no vuelve a facturar');
        $this->assertSame(1, Sale::withoutGlobalScopes()->where('id', $firstSaleId)->count());
    }

    public function test_rejects_a_request_with_an_invalid_signature(): void
    {
        $order = SubscriptionCheckoutOrder::factory()->for(Business::factory())->create(['status' => 'pending']);

        $response = $this->postJson('/api/webhooks/payments-core', [
            'event' => 'payment.approved',
            'reference' => $order->order_key,
        ], ['X-Nexolu-Timestamp' => (string) now()->timestamp, 'X-Nexolu-Signature' => 'bogus']);

        $response->assertStatus(401);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_approved_event_activates_the_subscription_and_records_the_payment(): void
    {
        $business = Business::factory()->create(['paid_until' => null]);
        $order = SubscriptionCheckoutOrder::factory()->for($business)->create([
            'status' => 'pending',
            'amount_cop' => 65000,
            'subscription_days' => 30,
            'provider' => 'wompi',
        ]);

        $response = $this->postSigned([
            'event' => 'payment.approved',
            'integration' => 'pos-nexolu',
            'transaction_id' => 'core-tx-1',
            'reference' => $order->order_key,
            'provider' => 'wompi',
            'provider_transaction_id' => 'wompi-tx-1',
            'amount_cop' => 65000,
            'currency' => 'COP',
            'status' => 'approved',
            'occurred_at' => now()->toIso8601String(),
            'metadata' => ['business_id' => (string) $business->id],
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('wompi-tx-1', $order->provider_order_id);
        $this->assertNotNull($order->confirmed_at);

        $business->refresh();
        $this->assertTrue($business->paid_until->isFuture());
        $this->assertEqualsWithDelta(30, now()->diffInDays($business->paid_until), 1);

        $this->assertDatabaseHas('saas_subscription_payments', [
            'business_id' => $business->id,
            'amount_cop' => 65000,
            'days_granted' => 30,
            'payment_method' => 'wompi',
        ]);
    }

    public function test_approved_event_is_idempotent_against_retries(): void
    {
        $business = Business::factory()->create(['paid_until' => null]);
        $order = SubscriptionCheckoutOrder::factory()->for($business)->create([
            'status' => 'pending',
            'subscription_days' => 30,
        ]);

        $payload = [
            'event' => 'payment.approved',
            'transaction_id' => 'core-tx-1',
            'reference' => $order->order_key,
            'provider_transaction_id' => 'wompi-tx-1',
            'status' => 'approved',
        ];

        $this->postSigned($payload)->assertOk();
        $paidUntilAfterFirst = $business->fresh()->paid_until;

        $this->postSigned($payload)->assertOk();
        $paidUntilAfterSecond = $business->fresh()->paid_until;

        $this->assertTrue($paidUntilAfterFirst->equalTo($paidUntilAfterSecond));
        $this->assertSame(1, SaasSubscriptionPayment::where('business_id', $business->id)->count());
    }

    public function test_declined_event_marks_the_order_as_failed_without_activating(): void
    {
        $business = Business::factory()->create(['paid_until' => null]);
        $order = SubscriptionCheckoutOrder::factory()->for($business)->create(['status' => 'pending']);

        $this->postSigned([
            'event' => 'payment.declined',
            'transaction_id' => 'core-tx-2',
            'reference' => $order->order_key,
            'status' => 'declined',
        ])->assertOk();

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertNull($business->fresh()->paid_until);
    }

    public function test_unknown_reference_is_acknowledged_without_error(): void
    {
        $this->postSigned([
            'event' => 'payment.approved',
            'transaction_id' => 'core-tx-3',
            'reference' => 'NEX-does-not-exist',
        ])->assertOk();
    }

    public function test_approved_event_queues_result_emails_to_the_business_admin_and_superadmins(): void
    {
        Mail::fake();

        $business = Business::factory()->create(['paid_until' => null]);
        $admin = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $superadmin = $this->superadmin();
        $order = SubscriptionCheckoutOrder::factory()->for($business)->create([
            'status' => 'pending',
            'amount_cop' => 65000,
            'subscription_days' => 30,
        ]);

        $this->postSigned([
            'event' => 'payment.approved',
            'transaction_id' => 'core-tx-1',
            'reference' => $order->order_key,
            'provider_transaction_id' => 'wompi-tx-1',
            'status' => 'approved',
        ])->assertOk();

        Mail::assertQueued(SubscriptionPaymentResultMail::class, fn ($mail) => $mail->hasTo($admin->email) && $mail->succeeded === true && $mail->daysGranted === 30
        );
        Mail::assertQueued(SubscriptionPaymentSuperadminNoticeMail::class, fn ($mail) => $mail->hasTo($superadmin->email) && $mail->succeeded === true
        );
    }

    public function test_declined_event_queues_failure_emails(): void
    {
        Mail::fake();

        $business = Business::factory()->create(['paid_until' => null]);
        $admin = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $this->superadmin();
        $order = SubscriptionCheckoutOrder::factory()->for($business)->create(['status' => 'pending']);

        $this->postSigned([
            'event' => 'payment.declined',
            'transaction_id' => 'core-tx-2',
            'reference' => $order->order_key,
            'status' => 'DECLINED',
        ])->assertOk();

        Mail::assertQueued(SubscriptionPaymentResultMail::class, fn ($mail) => $mail->hasTo($admin->email) && $mail->succeeded === false && $mail->failureStatus === 'DECLINED'
        );
        Mail::assertQueued(SubscriptionPaymentSuperadminNoticeMail::class);
    }

    public function test_approved_event_credits_an_ai_message_pack_order(): void
    {
        $business = Business::factory()->create(['ai_message_pack_balance' => 500]);
        $order = AiMessagePackCheckoutOrder::factory()->for($business)->create([
            'status' => 'pending',
            'messages' => 1000,
            'price_cop' => 15000,
        ]);

        $response = $this->postSigned([
            'event' => 'payment.approved',
            'transaction_id' => 'core-tx-pack-1',
            'reference' => $order->order_key,
            'provider_transaction_id' => 'wompi-tx-pack-1',
            'status' => 'approved',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('wompi-tx-pack-1', $order->provider_order_id);
        $this->assertNotNull($order->confirmed_at);

        $this->assertSame(1500, $business->fresh()->ai_message_pack_balance);
        $this->assertDatabaseHas('ai_message_pack_purchases', [
            'business_id' => $business->id,
            'messages' => 1000,
            'price_cop' => 15000,
        ]);
    }

    public function test_approved_event_for_an_ai_message_pack_order_is_idempotent_against_retries(): void
    {
        $business = Business::factory()->create(['ai_message_pack_balance' => 0]);
        $order = AiMessagePackCheckoutOrder::factory()->for($business)->create(['status' => 'pending', 'messages' => 1000]);

        $payload = [
            'event' => 'payment.approved',
            'transaction_id' => 'core-tx-pack-2',
            'reference' => $order->order_key,
            'status' => 'approved',
        ];

        $this->postSigned($payload)->assertOk();
        $this->postSigned($payload)->assertOk();

        $this->assertSame(1000, $business->fresh()->ai_message_pack_balance);
        $this->assertSame(1, AiMessagePackCheckoutOrder::where('order_key', $order->order_key)->count());
    }

    public function test_declined_event_marks_an_ai_message_pack_order_as_failed_without_crediting(): void
    {
        $business = Business::factory()->create(['ai_message_pack_balance' => 0]);
        $order = AiMessagePackCheckoutOrder::factory()->for($business)->create(['status' => 'pending']);

        $this->postSigned([
            'event' => 'payment.declined',
            'transaction_id' => 'core-tx-pack-3',
            'reference' => $order->order_key,
            'status' => 'declined',
        ])->assertOk();

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(0, $business->fresh()->ai_message_pack_balance);
    }

    public function test_voided_event_cancels_an_ai_message_pack_order(): void
    {
        $order = AiMessagePackCheckoutOrder::factory()->create(['status' => 'pending']);

        $this->postSigned([
            'event' => 'payment.voided',
            'transaction_id' => 'core-tx-pack-4',
            'reference' => $order->order_key,
        ])->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
    }
}
