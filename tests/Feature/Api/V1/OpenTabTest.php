<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessTable;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePartialPayment;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OpenTabTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_open_a_tab_without_a_payment_method(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('payment_method', null)
            ->assertJsonPath('total', '20000.00');

        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_cannot_open_a_second_tab_on_a_table_that_already_has_one(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $table = BusinessTable::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        Sale::factory()->create(['business_id' => $business->id, 'table_id' => $table->id, 'status' => 'open']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/open-tabs', [
                'table_id' => $table->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['table_id']);
    }

    public function test_adding_items_increases_the_total_and_decreases_stock(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 5000, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/open-tabs/{$tab['id']}/items", [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ])
            ->assertOk()
            ->assertJsonPath('total', '15000.00');

        $this->assertSame(7, $product->fresh()->stock);
    }

    public function test_syncing_items_replaces_the_cart_and_adjusts_stock_by_delta(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $productA = Product::factory()->create(['business_id' => $business->id, 'price' => 1000, 'stock' => 10]);
        $productB = Product::factory()->create(['business_id' => $business->id, 'price' => 2000, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $productA->id, 'quantity' => 3]],
        ])->json();
        $this->assertSame(7, $productA->fresh()->stock);

        // Reemplaza el carrito: quita A, agrega B.
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/open-tabs/{$tab['id']}/items", [
                'items' => [['product_id' => $productB->id, 'quantity' => 2]],
            ])
            ->assertOk()
            ->assertJsonPath('total', '4000.00')
            ->assertJsonCount(1, 'items');

        $this->assertSame(10, $productA->fresh()->stock);
        $this->assertSame(8, $productB->fresh()->stock);
    }

    public function test_a_single_partial_payment_covering_the_full_total_auto_closes_the_tab(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $final = $this->actingAs($user, 'sanctum')->postJson("/api/v1/open-tabs/{$tab['id']}/partial-payments", [
            'amount' => 10000,
            'payment_method' => 'cash',
        ]);

        $final->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('payment_method', 'cash')
            ->assertJsonPath('invoice_number', 'FAC-000001');
    }

    public function test_two_partial_payments_that_complete_the_total_close_as_mixed(): void
    {
        // Cada abono es un evento de cobro separado, guardado como su propio
        // SalePaymentSplit - aunque los dos sean en efectivo, el cierre queda
        // marcado 'mixed' con dos splits. Es el comportamiento real del legacy
        // (applyMixedPaymentRows), no un bug: "mixed" aqui significa "hubo mas
        // de una transaccion de cobro", no "mas de un medio distinto".
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/open-tabs/{$tab['id']}/partial-payments", [
            'amount' => 4000,
            'payment_method' => 'cash',
        ])->assertOk()->assertJsonPath('status', 'open');

        $final = $this->actingAs($user, 'sanctum')->postJson("/api/v1/open-tabs/{$tab['id']}/partial-payments", [
            'amount' => 6000,
            'payment_method' => 'cash',
        ]);

        $final->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('payment_method', 'mixed')
            ->assertJsonCount(2, 'payment_splits');
    }

    public function test_partial_payment_cannot_exceed_the_remaining_balance(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/open-tabs/{$tab['id']}/partial-payments", [
                'amount' => 50000,
                'payment_method' => 'cash',
            ])
            ->assertStatus(422);
    }

    public function test_partial_payment_rejects_credit_as_a_method(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/open-tabs/{$tab['id']}/partial-payments", [
                'amount' => 1000,
                'payment_method' => 'credit',
            ])
            ->assertStatus(422);
    }

    public function test_closing_with_a_single_payment_method(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/open-tabs/{$tab['id']}/close", ['payment_method' => 'transfer'])
            ->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('payment_method', 'transfer')
            ->assertJsonPath('invoice_number', 'FAC-000001');
    }

    public function test_closing_with_credit_flags_the_sale_as_credit(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/open-tabs/{$tab['id']}/close", ['payment_method' => 'credit'])
            ->assertOk()
            ->assertJsonPath('is_credit', true);
    }

    public function test_closing_with_mixed_payment_splits(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/open-tabs/{$tab['id']}/close", [
            'payment_splits' => [
                ['method' => 'cash', 'amount' => 6000],
                ['method' => 'transfer', 'amount' => 4000],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('payment_method', 'mixed')
            ->assertJsonCount(2, 'payment_splits');
    }

    public function test_closing_as_courtesy_requires_no_payment_method(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/open-tabs/{$tab['id']}/close", [
                'is_non_revenue' => true,
                'non_revenue_reason' => 'Cortesia',
            ])
            ->assertOk()
            ->assertJsonPath('is_non_revenue', true)
            ->assertJsonPath('payment_method', null);
    }

    public function test_closing_without_a_payment_method_or_splits_fails(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/open-tabs/{$tab['id']}/close", [])
            ->assertStatus(422);
    }

    public function test_charges_are_calculated_server_side_at_close(): void
    {
        $business = Business::factory()->create([
            'service_charge_enabled' => true,
            'service_charge_rate' => 10,
            'ipoconsumo_enabled' => false,
        ]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->assertSame('10000.00', $tab['total']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/open-tabs/{$tab['id']}/close", [
            'payment_method' => 'cash',
        ]);

        $response->assertOk()
            ->assertJsonPath('service_charge_amount', '1000.00')
            ->assertJsonPath('total', '11000.00');
    }

    public function test_cancelling_a_tab_restores_stock_and_deletes_it(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->json();
        $this->assertSame(7, $product->fresh()->stock);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/open-tabs/{$tab['id']}")
            ->assertNoContent();

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseMissing('sales', ['id' => $tab['id']]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_ENTRY,
            'quantity' => 3,
            'reference' => "Ajuste venta #{$tab['id']}",
        ]);
    }

    public function test_cancelling_a_tab_with_partial_payments_is_blocked(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $tab = Sale::factory()->create(['business_id' => $business->id, 'status' => 'open', 'total' => 10000]);
        SalePartialPayment::factory()->create(['sale_id' => $tab->id, 'amount' => 3000]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/open-tabs/{$tab->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('sales', ['id' => $tab->id]);
    }

    public function test_user_cannot_view_a_tab_from_another_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $tab = Sale::factory()->create(['business_id' => $otherBusiness->id, 'status' => 'open']);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/open-tabs/{$tab->id}")
            ->assertNotFound();
    }

    public function test_open_tabs_module_is_blocked_when_the_feature_flag_is_disabled(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['open_tabs' => false]]);
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/open-tabs')
            ->assertForbidden();
    }
}
