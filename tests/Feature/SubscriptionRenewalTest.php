<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessPaymentSource;
use App\Models\SaasSubscriptionPayment;
use App\Models\SubscriptionCheckoutOrder;
use App\Models\User;
use App\Services\Messaging\Contracts\MessagingChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Cobro de la suscripcion despues de vencer (nov-2026): antes un negocio
 * vencido seguia vendiendo sin que nadie le cobrara (Las Banquitas). Cubre
 * los dias de gracia y el bloqueo, que el pago tardio cuente desde el
 * vencimiento, el cobro automatico al medio guardado con sus reintentos y
 * los avisos por WhatsApp.
 */
class SubscriptionRenewalTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'webhook-secret-test';

    /** @var list<array{to: string, text: string, template: string}> */
    private array $whatsApps = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'subscriptions.lockout_not_before' => null,
            'services.payments_core.api_key' => 'test-payments-core-key',
            'services.payments_core.base_url' => 'http://payments-core.test',
            'services.payments_core.webhook_secret' => self::SECRET,
            'services.whatsapp.templates.recordatorio' => ['name' => 'general_reminder', 'lang' => 'es_CO'],
            'services.whatsapp.templates.suscripcion' => ['name' => 'subscription_update', 'lang' => 'es_CO', 'pending_approval' => true],
        ]);

        $channel = Mockery::mock(MessagingChannel::class);
        $channel->shouldReceive('isConfigured')->andReturn(true);
        $channel->shouldReceive('sendTemplate')->andReturnUsing(function (string $to, string $name, string $lang, array $components) {
            $this->whatsApps[] = ['to' => $to, 'text' => $components[0]['parameters'][0]['text'], 'template' => $name];

            return true;
        });
        $this->app->instance(MessagingChannel::class, $channel);
    }

    // ---------------------------------------------------------------
    // Pago tardio: cuenta desde el vencimiento
    // ---------------------------------------------------------------

    public function test_a_late_payment_counts_from_the_expiry_date_not_from_the_payment(): void
    {
        $expiredAt = now()->subDays(4)->setTime(19, 47);
        $business = Business::factory()->create(['trial_ends_at' => now()->subMonths(2), 'paid_until' => $expiredAt]);

        $business->activate(30);

        $this->assertTrue($business->fresh()->paid_until->equalTo($expiredAt->copy()->addDays(30)));
    }

    public function test_coming_back_after_more_than_a_cycle_counts_from_today(): void
    {
        $business = Business::factory()->create(['trial_ends_at' => now()->subMonths(4), 'paid_until' => now()->subDays(45)]);

        $this->travelTo(now()->startOfMinute());
        $business->activate(30);

        $this->assertTrue($business->fresh()->paid_until->equalTo(now()->addDays(30)));
    }

    public function test_a_first_payment_after_the_trial_counts_from_today(): void
    {
        $business = Business::factory()->create(['trial_ends_at' => now()->subDays(3), 'paid_until' => null]);

        $this->travelTo(now()->startOfMinute());
        $business->activate(30);

        $this->assertTrue($business->fresh()->paid_until->equalTo(now()->addDays(30)));
    }

    public function test_paying_before_expiry_still_chains_to_the_current_period(): void
    {
        $paidUntil = now()->addDays(5)->startOfSecond();
        $business = Business::factory()->create(['paid_until' => $paidUntil]);

        $business->activate(30);

        $this->assertTrue($business->fresh()->paid_until->equalTo($paidUntil->copy()->addDays(30)));
    }

    // ---------------------------------------------------------------
    // Gracia y bloqueo
    // ---------------------------------------------------------------

    public function test_an_expired_business_keeps_working_during_the_two_grace_days(): void
    {
        [, $user] = $this->expiredBusiness(daysAgo: 2);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/summary')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/business')
            ->assertJsonPath('access_locked', false)
            ->assertJsonPath('subscription_status', 'expired')
            ->assertJsonPath('grace_ends_at', fn ($value) => $value !== null);
    }

    public function test_after_the_grace_days_only_paying_is_allowed(): void
    {
        [, $user] = $this->expiredBusiness(daysAgo: 3);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/summary')
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_locked');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/products')->assertStatus(402);

        // Lo que hace falta para pagar y para que la app sepa que esta bloqueada.
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/business')->assertOk()->assertJsonPath('access_locked', true);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/subscription/status')->assertOk()->assertJsonPath('access_locked', true);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/payment-sources')->assertOk();
    }

    public function test_the_rollout_date_postpones_the_lockout_for_businesses_already_expired(): void
    {
        config(['subscriptions.lockout_not_before' => now()->addDay()->toDateString()]);
        [, $user] = $this->expiredBusiness(daysAgo: 10);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/summary')->assertOk();
    }

    public function test_a_business_on_trial_or_paid_is_never_locked(): void
    {
        $this->assertFalse(Business::factory()->create()->isLockedOut());
        $this->assertFalse(Business::factory()->create(['trial_ends_at' => now()->subYear(), 'paid_until' => now()->addDay()])->isLockedOut());
        // Sin ninguna fecha: un alta a medias, no un moroso.
        $this->assertFalse(Business::factory()->create(['trial_ends_at' => null, 'paid_until' => null])->isLockedOut());
    }

    // ---------------------------------------------------------------
    // Cobro automatico (subscriptions:renew)
    // ---------------------------------------------------------------

    public function test_the_saved_payment_source_is_charged_the_day_the_subscription_expires(): void
    {
        $this->fakePaymentsCore();
        [$business] = $this->expiredBusiness(daysAgo: 0, withSource: true);

        $this->artisan('subscriptions:renew')->assertSuccessful();

        $order = SubscriptionCheckoutOrder::where('business_id', $business->id)->sole();
        $this->assertSame('auto_renew', $order->origin);
        $this->assertSame('pending', $order->status);
        $this->assertSame(65000, $order->amount_cop);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/charge')
            && $request['payment_method']['type'] === 'PAYMENT_SOURCE'
            && $request['payment_method']['payment_source_id'] === 'src-'.$business->id);

        // Mismo dia, otra corrida: no se cobra dos veces.
        $this->artisan('subscriptions:renew')->assertSuccessful();
        $this->assertSame(1, SubscriptionCheckoutOrder::where('business_id', $business->id)->count());
    }

    public function test_retries_only_on_the_configured_days(): void
    {
        $this->fakePaymentsCore();
        [$retryDay] = $this->expiredBusiness(daysAgo: 4, withSource: true);
        [$restDay] = $this->expiredBusiness(daysAgo: 3, withSource: true);

        $this->artisan('subscriptions:renew')->assertSuccessful();

        $this->assertSame(1, SubscriptionCheckoutOrder::where('business_id', $retryDay->id)->count());
        $this->assertSame(0, SubscriptionCheckoutOrder::where('business_id', $restDay->id)->count());
    }

    public function test_now_charges_a_business_outside_the_retry_days(): void
    {
        $this->fakePaymentsCore();
        [$business] = $this->expiredBusiness(daysAgo: 5, withSource: true);

        // Dia 5 no es de reintento: sin --now no cobra.
        $this->artisan('subscriptions:renew', ['--business_id' => $business->id])->assertSuccessful();
        $this->assertSame(0, SubscriptionCheckoutOrder::where('business_id', $business->id)->count());

        // Y aunque ya se reviso hoy, --now lo cobra.
        $this->artisan('subscriptions:renew', ['--business_id' => $business->id, '--now' => true])->assertSuccessful();
        $this->assertSame('auto_renew', SubscriptionCheckoutOrder::where('business_id', $business->id)->sole()->origin);
    }

    public function test_now_requires_a_single_business(): void
    {
        $this->artisan('subscriptions:renew', ['--now' => true])->assertFailed();
    }

    public function test_preview_sends_every_message_to_one_number_without_touching_the_business(): void
    {
        [$business] = $this->expiredBusiness(daysAgo: 4, withSource: true);
        $paidUntil = $business->paid_until->copy();

        $this->artisan('subscriptions:preview-messages', ['phone' => '319 524 4852', '--business_id' => $business->id, '--pause' => 0])
            ->assertSuccessful();

        $this->assertCount(9, $this->whatsApps);
        $this->assertSame(['573195244852'], array_values(array_unique(array_column($this->whatsApps, 'to'))));
        $texts = array_column($this->whatsApps, 'text');
        $this->assertStringContainsString('Visa •••• 4242', $texts[0]);
        $this->assertStringContainsString('renovamos tu suscripción', $texts[2]);
        $this->assertStringContainsString('Lo intentaremos de nuevo el', $texts[3]);
        $this->assertStringContainsString('bloqueado hasta que se pague', $texts[4]);
        $this->assertStringContainsString('Camila intentó usar', $texts[8]);
        foreach ($texts as $text) {
            $this->assertLessThanOrEqual(300, mb_strlen($text));
        }
        $this->assertTrue($business->fresh()->paid_until->equalTo($paidUntil));
    }

    public function test_an_annual_customer_renews_for_a_year(): void
    {
        $this->fakePaymentsCore();
        [$business] = $this->expiredBusiness(daysAgo: 0, withSource: true);
        // Su ultimo pago fue el anual de hace un ano.
        SaasSubscriptionPayment::where('business_id', $business->id)->delete();
        SaasSubscriptionPayment::factory()->create(['business_id' => $business->id, 'days_granted' => 365, 'paid_at' => now()->subYear()]);

        $this->artisan('subscriptions:renew')->assertSuccessful();

        $this->assertSame(365, SubscriptionCheckoutOrder::where('business_id', $business->id)->sole()->subscription_days);
    }

    public function test_with_auto_renew_off_it_does_not_charge_and_sends_a_reminder_instead(): void
    {
        $this->fakePaymentsCore();
        [$business] = $this->expiredBusiness(daysAgo: 0, withSource: true);
        $business->update(['subscription_auto_renew' => false]);

        $this->artisan('subscriptions:renew')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertCount(1, $this->whatsApps);
        $this->assertStringContainsString('vence hoy', $this->whatsApps[0]['text']);
    }

    public function test_without_a_saved_source_it_reminds_on_due_day_last_grace_day_and_lockout(): void
    {
        $expectations = [0 => 'vence hoy', 1 => null, 2 => 'último día', 3 => 'suspendido'];

        foreach ($expectations as $daysAgo => $expected) {
            $this->whatsApps = [];
            [$business] = $this->expiredBusiness(daysAgo: $daysAgo);

            $this->artisan('subscriptions:renew', ['--business_id' => $business->id])->assertSuccessful();

            if ($expected === null) {
                $this->assertSame([], $this->whatsApps, "dia {$daysAgo}");
            } else {
                $this->assertCount(1, $this->whatsApps, "dia {$daysAgo}");
                $this->assertStringContainsString($expected, $this->whatsApps[0]['text']);
                $this->assertSame('573001112233', $this->whatsApps[0]['to']);
            }
        }
    }

    public function test_the_three_day_notice_says_when_and_how_much_will_be_charged(): void
    {
        Mail::fake();
        [$business] = $this->expiredBusiness(daysAgo: -2, withSource: true);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        $this->assertCount(1, $this->whatsApps);
        $this->assertStringContainsString('intentaremos el cobro de $65.000 a tu medio de pago registrado (Visa •••• 4242)', $this->whatsApps[0]['text']);
        $this->assertStringContainsString('saldo o cupo disponible', $this->whatsApps[0]['text']);
        $this->assertNotNull($business->fresh()->subscription_expiry_notified_at);
    }

    public function test_while_its_template_is_in_review_it_goes_as_a_reminder_with_the_link(): void
    {
        [$business] = $this->expiredBusiness(daysAgo: 0);

        $this->artisan('subscriptions:renew', ['--business_id' => $business->id])->assertSuccessful();

        $this->assertSame('general_reminder', $this->whatsApps[0]['template']);
        $this->assertStringEndsWith('Puedes pagar aquí: '.rtrim((string) config('app.frontend_url'), '/').'/suscripcion', $this->whatsApps[0]['text']);
    }

    public function test_once_approved_it_uses_its_own_template_and_leaves_the_link_to_the_button(): void
    {
        config(['services.whatsapp.templates.suscripcion.pending_approval' => false]);
        [$business] = $this->expiredBusiness(daysAgo: 0);

        $this->artisan('subscriptions:renew', ['--business_id' => $business->id])->assertSuccessful();

        $this->assertSame('subscription_update', $this->whatsApps[0]['template']);
        $this->assertStringContainsString('vence hoy', $this->whatsApps[0]['text']);
        $this->assertStringNotContainsString('/suscripcion', $this->whatsApps[0]['text']);
        $this->assertStringEndsWith('hasta que pagues.', $this->whatsApps[0]['text']);
    }

    // ---------------------------------------------------------------
    // Resultado del cobro automatico (webhook)
    // ---------------------------------------------------------------

    public function test_an_approved_auto_renewal_extends_from_the_expiry_and_confirms_by_whatsapp(): void
    {
        Mail::fake();
        [$business] = $this->expiredBusiness(daysAgo: 1, withSource: true);
        $expiredAt = $business->paid_until->copy();
        $order = $this->autoRenewOrder($business);

        $this->postSigned(['event' => 'payment.approved', 'reference' => $order->order_key, 'transaction_id' => 'tx-1'])->assertOk();

        $this->assertTrue($business->fresh()->paid_until->equalTo($expiredAt->addDays(30)));
        $this->assertCount(1, $this->whatsApps);
        $this->assertStringContainsString('renovamos tu suscripción', $this->whatsApps[0]['text']);
    }

    public function test_a_declined_auto_renewal_tells_the_owner_when_it_retries(): void
    {
        Mail::fake();
        [$business] = $this->expiredBusiness(daysAgo: 0, withSource: true);
        $order = $this->autoRenewOrder($business);

        $this->postSigned(['event' => 'payment.declined', 'reference' => $order->order_key, 'status' => 'DECLINED'])->assertOk();

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertCount(1, $this->whatsApps);
        $this->assertStringContainsString('No pudimos cobrar', $this->whatsApps[0]['text']);
        $this->assertStringContainsString('Lo intentaremos de nuevo el', $this->whatsApps[0]['text']);
    }

    public function test_a_declined_manual_checkout_does_not_send_a_whatsapp(): void
    {
        Mail::fake();
        [$business] = $this->expiredBusiness(daysAgo: 0);
        $order = SubscriptionCheckoutOrder::factory()->create(['business_id' => $business->id, 'status' => 'pending', 'origin' => 'checkout']);

        $this->postSigned(['event' => 'payment.declined', 'reference' => $order->order_key])->assertOk();

        $this->assertSame([], $this->whatsApps);
    }

    // ---------------------------------------------------------------
    // El empleado que se encuentra el bloqueo
    // ---------------------------------------------------------------

    public function test_an_employee_can_tell_the_owner_the_system_is_locked_once_an_hour(): void
    {
        [$business] = $this->expiredBusiness(daysAgo: 5);
        $employee = User::factory()->create(['business_id' => $business->id, 'name' => 'Pedro Caja']);

        $this->actingAs($employee, 'sanctum')->postJson('/api/v1/subscription/notify-admin')->assertOk();
        $this->actingAs($employee, 'sanctum')->postJson('/api/v1/subscription/notify-admin')->assertOk();

        $this->assertCount(1, $this->whatsApps);
        $this->assertStringContainsString('Pedro Caja intentó usar Nexolú POS', $this->whatsApps[0]['text']);
    }

    public function test_notify_admin_does_nothing_when_the_business_is_not_locked(): void
    {
        [$business] = $this->expiredBusiness(daysAgo: -10);
        $employee = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($employee, 'sanctum')->postJson('/api/v1/subscription/notify-admin')->assertStatus(422);
        $this->assertSame([], $this->whatsApps);
    }

    // ---------------------------------------------------------------

    /**
     * Negocio que pago y vencio hace $daysAgo dias (negativo: vence en el
     * futuro). Dueño admin con celular, para los avisos por WhatsApp.
     *
     * @return array{0: Business, 1: User}
     */
    private function expiredBusiness(int $daysAgo, bool $withSource = false): array
    {
        $business = Business::factory()->create([
            'subscription_plan' => 'basic',
            'custom_price_cop' => 65000,
            'trial_ends_at' => now()->subMonths(3),
            'created_at' => now()->subMonths(4),
            // Un rato despues de la corrida de las 07:00, como un vencimiento real.
            'paid_until' => now()->subDays($daysAgo)->endOfDay()->subHours(4),
        ]);
        SaasSubscriptionPayment::factory()->create(['business_id' => $business->id, 'days_granted' => 30, 'paid_at' => now()->subDays($daysAgo + 30)]);

        $owner = User::factory()->create([
            'business_id' => $business->id,
            'is_business_owner' => true,
            'cellphone' => '300 111 2233',
        ]);
        $owner->assignRole('admin');

        if ($withSource) {
            BusinessPaymentSource::factory()->create([
                'business_id' => $business->id,
                'payment_source_id' => 'src-'.$business->id,
                'label' => 'Visa •••• 4242',
            ]);
        }

        return [$business, $owner];
    }

    private function autoRenewOrder(Business $business): SubscriptionCheckoutOrder
    {
        return SubscriptionCheckoutOrder::factory()->create([
            'business_id' => $business->id,
            'amount_cop' => 65000,
            'subscription_days' => 30,
            'status' => 'pending',
            'origin' => 'auto_renew',
        ]);
    }

    private function fakePaymentsCore(): void
    {
        Http::fake([
            'payments-core.test/v1/payments/intents' => fn () => Http::response(['reference' => 'pay_auto_'.uniqid(), 'provider' => 'wompi', 'checkout' => []], 201),
            'payments-core.test/v1/payments/intents/*/charge' => Http::response(['status' => 'PENDING'], 200),
        ]);
    }

    private function postSigned(array $payload): TestResponse
    {
        $body = json_encode($payload);
        $timestamp = (string) now()->timestamp;

        return $this->call('POST', '/api/webhooks/payments-core', [], [], [], [
            'HTTP_X-Nexolu-Timestamp' => $timestamp,
            'HTTP_X-Nexolu-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}
