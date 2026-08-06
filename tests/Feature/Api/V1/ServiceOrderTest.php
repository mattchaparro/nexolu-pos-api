<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Client;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ServiceOrderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_create_a_simple_service_order_with_a_flat_total(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $client = Client::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/service-orders', [
            'client_id' => $client->id,
            'service_name' => 'Corte de cabello',
            'total' => 30000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('service_name', 'Corte de cabello')
            ->assertJsonPath('total', '30000.00')
            ->assertJsonPath('status', 'pending');
    }

    public function test_user_can_create_an_itemized_service_order(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/service-orders', [
            'service_name' => 'Paquete de belleza',
            'items' => [
                ['name' => 'Corte', 'quantity' => 1, 'unit_price' => 20000],
                ['name' => 'Manicure', 'quantity' => 1, 'unit_price' => 15000],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('total', '35000.00')
            ->assertJsonCount(2, 'items');
    }

    public function test_creating_without_a_total_or_items_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/service-orders', [
            'service_name' => 'Sin total',
        ])->assertStatus(422)->assertJsonValidationErrors(['total']);
    }

    public function test_initial_payment_is_capped_and_updates_status(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/service-orders', [
            'service_name' => 'Masaje',
            'total' => 50000,
            'initial_payment' => 999999,
            'initial_payment_method' => 'cash',
        ]);

        $response->assertCreated()
            ->assertJsonPath('amount_paid', '50000.00')
            ->assertJsonPath('status', 'paid')
            ->assertJsonCount(1, 'payments');
    }

    public function test_pay_registers_an_abono_and_caps_it_to_the_balance(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $order = ServiceOrder::factory()->create(['business_id' => $business->id, 'total' => 40000, 'amount_paid' => 0]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/service-orders/{$order->id}/pay", [
                'amount' => 999999,
                'payment_method' => 'cash',
            ]);

        $response->assertOk()->assertJsonPath('amount_paid', '40000.00')->assertJsonPath('status', 'paid');
    }

    public function test_pay_forbids_the_credit_method(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $order = ServiceOrder::factory()->create(['business_id' => $business->id, 'total' => 40000, 'amount_paid' => 0]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/service-orders/{$order->id}/pay", ['amount' => 10000, 'payment_method' => 'credit'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);
    }

    public function test_pay_on_a_fully_paid_order_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $order = ServiceOrder::factory()->create([
            'business_id' => $business->id, 'total' => 40000, 'amount_paid' => 40000, 'status' => 'paid',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/service-orders/{$order->id}/pay", ['amount' => 1000, 'payment_method' => 'cash'])
            ->assertStatus(422);
    }

    public function test_update_never_drops_the_total_below_what_was_already_paid(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $order = ServiceOrder::factory()->create(['business_id' => $business->id, 'total' => 40000, 'amount_paid' => 30000, 'status' => 'partial']);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/service-orders/{$order->id}", [
                'service_name' => 'Servicio recortado',
                'total' => 10000,
            ]);

        $response->assertOk()->assertJsonPath('total', '30000.00');
    }

    public function test_update_replaces_items_and_recomputes_total(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $order = ServiceOrder::factory()->create(['business_id' => $business->id, 'total' => 20000, 'amount_paid' => 0]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/service-orders/{$order->id}", [
                'service_name' => 'Nuevo detalle',
                'items' => [['name' => 'Item nuevo', 'quantity' => 2, 'unit_price' => 5000]],
            ]);

        $response->assertOk()->assertJsonPath('total', '10000.00')->assertJsonCount(1, 'items');
    }

    public function test_updating_a_cancelled_order_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $order = ServiceOrder::factory()->cancelled()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/service-orders/{$order->id}", ['service_name' => 'x', 'total' => 1000])
            ->assertStatus(422);
    }

    public function test_cancel_refunds_payments_as_negative_rows_and_resets_amount_paid(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $order = ServiceOrder::factory()->create(['business_id' => $business->id, 'total' => 30000, 'amount_paid' => 30000, 'status' => 'paid']);
        $order->payments()->create([
            'business_id' => $business->id, 'amount' => 30000, 'payment_method' => 'cash', 'recorded_by_user_id' => $user->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/service-orders/{$order->id}/cancel")
            ->assertNoContent();

        $this->assertDatabaseHas('service_orders', ['id' => $order->id, 'status' => 'cancelled', 'amount_paid' => 0]);
        $this->assertDatabaseHas('service_payments', [
            'service_order_id' => $order->id,
            'amount' => -30000,
            'payment_method' => 'cash',
        ]);
    }

    public function test_cancelling_an_already_cancelled_order_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $order = ServiceOrder::factory()->cancelled()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/service-orders/{$order->id}/cancel")
            ->assertStatus(422);
    }

    public function test_user_can_only_see_their_business_service_orders(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        ServiceOrder::factory()->count(2)->create(['business_id' => $business->id]);
        ServiceOrder::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/service-orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
