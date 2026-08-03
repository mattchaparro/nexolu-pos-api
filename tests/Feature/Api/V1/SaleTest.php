<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Discount;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_create_a_simple_cash_sale(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 20]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('total', '20000.00')
            ->assertJsonPath('payment_method', 'cash')
            ->assertJsonPath('invoice_number', 'FAC-000001')
            ->assertJsonPath('items.0.quantity', 2);

        $this->assertSame(18, $product->fresh()->stock);
    }

    public function test_invoice_numbers_increment_sequentially(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 100]);

        $payload = ['payment_method' => 'cash', 'items' => [['product_id' => $product->id, 'quantity' => 1]]];

        $first = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', $payload);
        $second = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', $payload);

        $first->assertJsonPath('invoice_number', 'FAC-000001');
        $second->assertJsonPath('invoice_number', 'FAC-000002');
    }

    public function test_sale_requires_at_least_one_item(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sales', ['payment_method' => 'cash', 'items' => []])
            ->assertStatus(422);
    }

    public function test_sale_rejects_a_product_from_another_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreignProduct = Product::factory()->create(['business_id' => $otherBusiness->id]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sales', [
                'payment_method' => 'cash',
                'items' => [['product_id' => $foreignProduct->id, 'quantity' => 1]],
            ])
            ->assertStatus(422);
    }

    public function test_sale_with_insufficient_stock_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'track_stock' => true, 'stock' => 2]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sales', [
                'payment_method' => 'cash',
                'items' => [['product_id' => $product->id, 'quantity' => 5]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);

        $this->assertSame(2, $product->fresh()->stock);
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
            ->postJson('/api/v1/sales', [
                'payment_method' => 'cash',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.unit_price']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 7500]],
        ]);

        $response->assertCreated()->assertJsonPath('total', '7500.00');
    }

    public function test_item_discount_reduces_the_total(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);
        $discount = Discount::factory()->fixed()->itemScoped()->create(['business_id' => $business->id, 'value' => 2000]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'discount_id' => $discount->id]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('total', '8000.00')
            ->assertJsonPath('items.0.discount_amount', '2000.00');
    }

    public function test_cart_discount_reduces_the_total(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);
        $discount = Discount::factory()->create(['business_id' => $business->id, 'type' => 'percentage', 'scope' => 'cart', 'value' => 10]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'cart_discount_id' => $discount->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('total', '9000.00')
            ->assertJsonPath('cart_discount_amount', '1000.00');
    }

    public function test_service_charge_and_ipoconsumo_are_added_to_the_total(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'service_charge_amount' => 1000,
            'ipoconsumo_amount' => 800,
        ]);

        $response->assertCreated()->assertJsonPath('total', '11800.00');
    }

    public function test_non_revenue_sale_does_not_require_a_payment_method(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 5000, 'stock' => 10]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'is_non_revenue' => true,
            'non_revenue_reason' => 'Cortesia cliente frecuente',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('is_non_revenue', true)
            ->assertJsonPath('payment_method', null)
            ->assertJsonPath('is_credit', false);
    }

    public function test_credit_payment_method_flags_the_sale_as_credit(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 5000, 'stock' => 10]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'credit',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertCreated()->assertJsonPath('is_credit', true);
    }

    public function test_payment_method_must_be_one_the_business_has_configured(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/sales', [
                'payment_method' => 'bitcoin',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(422);
    }

    public function test_user_cannot_view_a_sale_from_another_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $sale = Sale::factory()->create(['business_id' => $otherBusiness->id]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/sales/{$sale->id}")
            ->assertNotFound();
    }

    public function test_reversing_a_sale_restores_stock_and_deletes_it(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 5000, 'stock' => 10]);

        $sale = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->json();

        $this->assertSame(7, $product->fresh()->stock);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/reverse")
            ->assertNoContent();

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseMissing('sales', ['id' => $sale['id']]);
    }

    public function test_sales_list_is_scoped_to_the_business(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        Sale::factory()->count(2)->create(['business_id' => $business->id]);
        Sale::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/sales')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
