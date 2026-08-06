<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registering_a_purchase_adds_stock_and_updates_weighted_average_cost(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $supplier = Supplier::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10, 'cost_price' => 1000]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'supplier_id' => $supplier->id,
            'purchased_at' => now()->toDateString(),
            'invoice_number' => 'FAC-001',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 10, 'line_total_cop' => 20000],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('total', 20000)
            ->assertJsonCount(1, 'lines');

        // stock 10 @1000 + 10 @2000 -> weighted average 1500
        $product->refresh();
        $this->assertSame(20, $product->stock);
        $this->assertEquals(1500, (float) $product->cost_price);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_ENTRY,
            'quantity' => 10,
            'unit_cost_cop' => 2000,
            'reference' => "Compra #{$response->json('id')}",
        ]);
        $this->assertDatabaseHas('product_cost_history', [
            'product_id' => $product->id,
            'cost_before' => 1000,
            'cost_after' => 1500,
            'source' => 'purchase',
        ]);
    }

    public function test_purchase_stock_movement_is_linked_to_its_purchase_line(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 0, 'cost_price' => 0]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'line_total_cop' => 10000]],
        ]);

        $lineId = $response->json('lines.0.id');
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'purchase_line_id' => $lineId,
        ]);
    }

    public function test_single_sale_products_cannot_be_purchased(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'is_single_sale' => true]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'line_total_cop' => 1000]],
        ])->assertStatus(422);
    }

    public function test_credit_purchase_stays_pending_with_a_balance(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'is_credit' => true,
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'line_total_cop' => 5000]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('payment_status', 'pending')
            ->assertJsonPath('balance', 5000);
    }

    public function test_create_expense_flag_creates_a_linked_expense_with_the_capitalized_vocabulary(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/purchases', [
            'purchased_at' => now()->toDateString(),
            'create_expense' => true,
            'expense_payment_method' => 'Nequi',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'line_total_cop' => 8000]],
        ]);

        $this->assertDatabaseHas('expenses', [
            'linkable_type' => Purchase::class,
            'linkable_id' => $response->json('id'),
            'value' => 8000,
            'payment_method' => 'Nequi',
        ]);
    }

    public function test_pay_caps_to_balance_marks_paid_and_creates_a_bridged_expense(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $purchase = Purchase::factory()->credit()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);
        $purchase->lines()->create([
            'product_id' => $product->id, 'quantity' => 1, 'unit_cost_cop' => 15000, 'line_total_cop' => 15000,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/purchases/{$purchase->id}/pay", [
                'amount' => 999999,
                'payment_method' => 'cash',
            ]);

        $response->assertOk()
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('balance', 0);

        $this->assertDatabaseHas('purchase_payments', ['purchase_id' => $purchase->id, 'amount' => 15000, 'payment_method' => 'cash']);
        $this->assertDatabaseHas('expenses', [
            'linkable_type' => Purchase::class,
            'linkable_id' => $purchase->id,
            'value' => 15000,
            'payment_method' => 'Efectivo',
        ]);
    }

    public function test_pay_forbids_the_credit_method(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $purchase = Purchase::factory()->credit()->create(['business_id' => $business->id]);
        $purchase->lines()->create([
            'product_id' => Product::factory()->create(['business_id' => $business->id])->id,
            'quantity' => 1, 'unit_cost_cop' => 5000, 'line_total_cop' => 5000,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/purchases/{$purchase->id}/pay", ['amount' => 1000, 'payment_method' => 'credit'])
            ->assertStatus(422);
    }

    public function test_paying_an_already_paid_purchase_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $purchase = Purchase::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/purchases/{$purchase->id}/pay", ['amount' => 1000, 'payment_method' => 'cash'])
            ->assertStatus(422);
    }

    public function test_user_can_only_see_their_business_purchases(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Purchase::factory()->count(2)->create(['business_id' => $business->id]);
        Purchase::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/purchases')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
