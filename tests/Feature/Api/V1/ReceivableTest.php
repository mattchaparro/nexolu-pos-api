<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessTable;
use App\Models\Client;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReceivableTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_credit_sale_creates_a_pending_receivable(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 20000, 'stock' => 10]);

        $sale = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'credit',
            'customer_name' => 'Juan Perez',
            'customer_phone' => '3001234567',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->assertDatabaseHas('receivables', [
            'business_id' => $business->id,
            'sale_id' => $sale['id'],
            'status' => 'pending',
            'amount' => '20000.00',
            'balance' => '20000.00',
        ]);
    }

    public function test_a_credit_sale_linked_to_a_client_propagates_client_id_to_the_receivable(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $client = Client::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 20000, 'stock' => 10]);

        $sale = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'credit',
            'customer_phone' => '3001234567',
            'client_id' => $client->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->assertDatabaseHas('receivables', [
            'business_id' => $business->id,
            'sale_id' => $sale['id'],
            'client_id' => $client->id,
        ]);
    }

    public function test_a_second_credit_sale_for_the_same_phone_merges_into_the_pending_receivable(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $payload = [
            'payment_method' => 'credit',
            'customer_name' => 'Juan Perez',
            'customer_phone' => '3001234567',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', $payload)->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', $payload)->assertCreated();

        $this->assertDatabaseCount('receivables', 1);
        $this->assertDatabaseHas('receivables', [
            'business_id' => $business->id,
            'amount' => '20000.00',
            'balance' => '20000.00',
        ]);
    }

    public function test_credit_sales_without_a_phone_or_id_do_not_merge_even_with_the_same_name(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $payload = [
            'payment_method' => 'credit',
            'customer_name' => 'Cliente Anonimo',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', $payload)->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', $payload)->assertCreated();

        $this->assertDatabaseCount('receivables', 2);
    }

    public function test_closing_an_open_tab_as_credit_creates_a_receivable(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $table = BusinessTable::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 15000, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'table_id' => $table->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/open-tabs/{$tab['id']}/close", [
                'payment_method' => 'credit',
                'customer_name' => 'Cliente Frecuente',
            ])
            ->assertOk();

        $this->assertDatabaseHas('receivables', [
            'business_id' => $business->id,
            'sale_id' => $tab['id'],
            'status' => 'pending',
            'balance' => '15000.00',
        ]);
    }

    public function test_a_non_credit_sale_does_not_create_a_receivable(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertDatabaseCount('receivables', 0);
    }

    public function test_user_can_collect_a_pending_receivable(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $receivable = Receivable::factory()->create(['business_id' => $business->id, 'amount' => 30000, 'balance' => 30000]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/receivables/{$receivable->id}/collect", [
            'payment_method' => 'cash',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('balance', '0.00')
            ->assertJsonPath('payment_method', 'cash');
    }

    public function test_receivable_cannot_be_collected_twice(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $receivable = Receivable::factory()->paid()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/receivables/{$receivable->id}/collect", ['payment_method' => 'cash'])
            ->assertStatus(422);
    }

    public function test_receivable_cannot_be_collected_with_credit_as_the_method(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $receivable = Receivable::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/receivables/{$receivable->id}/collect", ['payment_method' => 'credit'])
            ->assertStatus(422);
    }

    public function test_reversing_a_sale_with_a_pending_receivable_deletes_it(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $sale = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'credit',
            'customer_name' => 'Juan Perez',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/reverse")
            ->assertNoContent();

        $this->assertDatabaseMissing('receivables', ['sale_id' => $sale['id']]);
        $this->assertDatabaseMissing('sales', ['id' => $sale['id']]);
    }

    public function test_reversing_a_sale_whose_receivable_was_already_paid_is_blocked(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'price' => 10000, 'stock' => 10]);

        $sale = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'credit',
            'customer_name' => 'Juan Perez',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $receivable = Receivable::where('sale_id', $sale['id'])->firstOrFail();
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/receivables/{$receivable->id}/collect", ['payment_method' => 'cash'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/sales/{$sale['id']}/reverse")
            ->assertStatus(422);

        $this->assertDatabaseHas('sales', ['id' => $sale['id']]);
    }

    public function test_user_cannot_view_a_receivable_from_another_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $receivable = Receivable::factory()->create(['business_id' => $otherBusiness->id]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/receivables/{$receivable->id}")
            ->assertNotFound();
    }

    public function test_receivables_can_be_filtered_by_status_and_search(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        Receivable::factory()->create(['business_id' => $business->id, 'customer_name' => 'Maria Gomez']);
        Receivable::factory()->paid()->create(['business_id' => $business->id, 'customer_name' => 'Pedro Lopez']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/receivables?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_name', 'Maria Gomez');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/receivables?search=Pedro')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_receivables_can_be_filtered_by_a_creation_date_range(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $inRange = Receivable::factory()->create(['business_id' => $business->id, 'customer_name' => 'En rango']);
        $inRange->forceFill(['created_at' => '2026-03-15'])->save();
        $outOfRange = Receivable::factory()->create(['business_id' => $business->id, 'customer_name' => 'Fuera de rango']);
        $outOfRange->forceFill(['created_at' => '2026-01-01'])->save();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/receivables?date_from=2026-03-01&date_to=2026-03-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_name', 'En rango');
    }

    public function test_summary_reports_pending_and_collected_this_month_totals(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        Receivable::factory()->create(['business_id' => $business->id, 'amount' => 50000, 'balance' => 50000]);
        Receivable::factory()->create(['business_id' => $business->id, 'amount' => 30000, 'balance' => 30000]);
        Receivable::factory()->paid()->create(['business_id' => $business->id, 'amount' => 20000]);
        // Cobrado el mes pasado: no debe contar en "este mes".
        Receivable::factory()->paid()->create(['business_id' => $business->id, 'amount' => 99999])
            ->forceFill(['paid_at' => now()->subMonthNoOverflow()])->save();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/receivables/summary')->assertOk();

        $response->assertJsonPath('pending_count', 2)
            ->assertJsonPath('pending_amount', 80000)
            ->assertJsonPath('collected_this_month_count', 1)
            ->assertJsonPath('collected_this_month_amount', 20000);
    }

    public function test_receivables_module_is_blocked_when_the_feature_flag_is_disabled(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['receivables' => false]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/receivables')
            ->assertForbidden();
    }
}
