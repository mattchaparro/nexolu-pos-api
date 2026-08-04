<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Layaway;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LayawayTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_create_a_layaway_and_stock_is_reserved(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'customer_name' => 'Maria Perez',
            'customer_phone' => '3001234567',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('total', 30000)
            ->assertJsonPath('balance', 30000)
            ->assertJsonPath('items.0.quantity', 3);

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_EXIT,
            'quantity' => -3,
        ]);
    }

    public function test_creating_a_layaway_with_insufficient_stock_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 2]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertStatus(422);

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_price_varies_at_sale_requires_a_unit_price(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'price_varies_at_sale' => true,
            'price' => 1000,
            'stock' => 10,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/layaways', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.unit_price']);
    }

    public function test_create_with_an_initial_payment_registers_it(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'initial_payment' => 5000,
            'initial_payment_method' => 'cash',
        ]);

        $response->assertCreated()
            ->assertJsonPath('paid', 5000)
            ->assertJsonPath('balance', 15000)
            ->assertJsonCount(1, 'payments');
    }

    public function test_payment_is_capped_to_the_remaining_balance(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $layaway = $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/layaways/{$layaway['id']}/payments", [
                'amount' => 999999,
                'payment_method' => 'cash',
            ]);

        $response->assertOk()->assertJsonPath('balance', 0)->assertJsonPath('status', 'completed');
        $this->assertDatabaseHas('layaway_payments', ['layaway_id' => $layaway['id'], 'amount' => 10000]);
    }

    public function test_payment_forbids_the_credit_method(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $layaway = $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/layaways/{$layaway['id']}/payments", [
                'amount' => 5000,
                'payment_method' => 'credit',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);
    }

    public function test_payment_on_an_already_settled_layaway_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $layaway = $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'initial_payment' => 10000,
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/layaways/{$layaway['id']}/payments", ['amount' => 1000, 'payment_method' => 'cash'])
            ->assertStatus(422);
    }

    public function test_cancelling_restores_stock_and_refunds_payments_as_negative_rows(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $layaway = $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'initial_payment' => 15000,
            'initial_payment_method' => 'cash',
        ])->json();
        $this->assertSame(7, $product->fresh()->stock);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/layaways/{$layaway['id']}/cancel")
            ->assertNoContent();

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseHas('layaways', ['id' => $layaway['id'], 'status' => 'cancelled']);
        $this->assertDatabaseHas('layaway_payments', [
            'layaway_id' => $layaway['id'],
            'amount' => -15000,
            'payment_method' => 'cash',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_ENTRY,
            'quantity' => 3,
            'reference' => "Apartado #{$layaway['id']}",
        ]);
    }

    public function test_cancelling_an_already_cancelled_layaway_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $layaway = Layaway::factory()->cancelled()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/layaways/{$layaway->id}/cancel")
            ->assertStatus(422);
    }

    public function test_updating_items_adjusts_stock_by_delta(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $productA = Product::factory()->create(['business_id' => $business->id, 'price' => 1000, 'stock' => 10]);
        $productB = Product::factory()->create(['business_id' => $business->id, 'price' => 2000, 'stock' => 10]);

        $layaway = $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'items' => [['product_id' => $productA->id, 'quantity' => 3]],
        ])->json();
        $this->assertSame(7, $productA->fresh()->stock);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/layaways/{$layaway['id']}/items", [
                'items' => [['product_id' => $productB->id, 'quantity' => 2]],
            ]);

        $response->assertOk()->assertJsonCount(1, 'items');
        $this->assertSame(10, $productA->fresh()->stock);
        $this->assertSame(8, $productB->fresh()->stock);
    }

    public function test_updating_items_on_a_cancelled_layaway_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);
        $layaway = Layaway::factory()->cancelled()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/layaways/{$layaway->id}/items", [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(422);
    }

    public function test_manual_complete_action_marks_it_completed(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $layaway = $this->actingAs($user, 'sanctum')->postJson('/api/v1/layaways', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/layaways/{$layaway['id']}/complete")
            ->assertOk()
            ->assertJsonPath('status', 'completed');
    }

    public function test_completing_a_non_open_layaway_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $layaway = Layaway::factory()->cancelled()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/layaways/{$layaway->id}/complete")
            ->assertStatus(422);
    }

    public function test_user_can_only_see_their_business_layaways(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        Layaway::factory()->count(2)->create(['business_id' => $business->id]);
        Layaway::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/layaways')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
