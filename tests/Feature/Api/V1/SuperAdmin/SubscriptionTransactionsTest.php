<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\Business;
use App\Models\SubscriptionCheckoutOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class SubscriptionTransactionsTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_superadmin_can_list_subscription_checkout_orders(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        SubscriptionCheckoutOrder::factory()->create(['business_id' => $business->id, 'status' => 'confirmed']);
        SubscriptionCheckoutOrder::factory()->create(['business_id' => $business->id, 'status' => 'pending']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/subscription-transactions');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.business.id', $business->id);
    }

    /**
     * El motivo por el que la pasarela rechazo un cobro solo existe en el
     * payload del webhook. Sin exponerlo, un pago fallido en el panel es una
     * fila roja sin explicacion y hay que ir a los logs del servidor.
     */
    public function test_a_failed_order_exposes_what_the_gateway_answered(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();

        SubscriptionCheckoutOrder::factory()->create([
            'business_id' => $business->id,
            'status' => 'failed',
            'payload' => [
                'event' => 'payment.declined',
                'status' => 'DECLINED',
                'provider_transaction_id' => 'wompi-123',
                'fee_cop' => 0,
                'net_amount_cop' => 0,
            ],
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/subscription-transactions?status=failed')
            ->assertOk();

        $this->assertSame('DECLINED', $response->json('data.0.provider_status'));
        $this->assertSame('payment.declined', $response->json('data.0.provider_event'));
        $this->assertSame('wompi-123', $response->json('data.0.payload.provider_transaction_id'));
    }

    /**
     * Un pendiente viejo no es un cobro en curso: la pasarela avisa en
     * segundos. Sin distinguirlo, la lista de pendientes crece para siempre
     * mezclando checkouts abandonados con cobros de verdad en vuelo.
     */
    public function test_an_old_pending_order_is_flagged_as_stale(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();

        $fresh = SubscriptionCheckoutOrder::factory()->create(['business_id' => $business->id, 'status' => 'pending']);
        $old = SubscriptionCheckoutOrder::factory()->create(['business_id' => $business->id, 'status' => 'pending']);
        $old->forceFill(['created_at' => now()->subDay()])->save();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/subscription-transactions')
            ->assertOk();

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($rows[$old->id]['pending_stale']);
        $this->assertFalse($rows[$fresh->id]['pending_stale']);
    }

    public function test_the_summary_only_counts_collected_money_and_ignores_pending_in_the_success_rate(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();

        SubscriptionCheckoutOrder::factory()->count(3)->create([
            'business_id' => $business->id, 'status' => 'confirmed', 'amount_cop' => 50000,
        ]);
        SubscriptionCheckoutOrder::factory()->create(['business_id' => $business->id, 'status' => 'failed', 'amount_cop' => 50000]);
        SubscriptionCheckoutOrder::factory()->count(5)->create([
            'business_id' => $business->id, 'status' => 'pending', 'amount_cop' => 50000,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/subscription-transactions')
            ->assertOk();

        // Solo lo confirmado es plata que entro.
        $this->assertSame(150000, $response->json('summary.collected_cop'));
        // 3 de 4 RESUELTOS. Los 5 pendientes no son rechazos de la pasarela:
        // meterlos en el denominador daria 33% y no significa nada.
        $this->assertEquals(75.0, $response->json('summary.success_rate_pct'));
        $this->assertSame(9, $response->json('summary.orders'));
    }

    public function test_the_summary_follows_the_same_filters_as_the_list(): void
    {
        $admin = $this->superadmin();
        $mine = Business::factory()->create();
        $other = Business::factory()->create();

        SubscriptionCheckoutOrder::factory()->create(['business_id' => $mine->id, 'status' => 'confirmed', 'amount_cop' => 50000]);
        SubscriptionCheckoutOrder::factory()->count(4)->create(['business_id' => $other->id, 'status' => 'confirmed', 'amount_cop' => 90000]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/superadmin/subscription-transactions?business_id={$mine->id}")
            ->assertOk();

        $this->assertSame(50000, $response->json('summary.collected_cop'));
        $this->assertSame(1, $response->json('summary.orders'));
    }

    public function test_can_search_by_order_key(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();

        SubscriptionCheckoutOrder::factory()->create(['business_id' => $business->id, 'order_key' => 'nx-abc-999']);
        SubscriptionCheckoutOrder::factory()->create(['business_id' => $business->id, 'order_key' => 'nx-zzz-111']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/subscription-transactions?search=abc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_key', 'nx-abc-999');
    }
}
