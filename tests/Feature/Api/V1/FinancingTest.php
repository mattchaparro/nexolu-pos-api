<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\FinancingCredit;
use App\Models\FinancingProvider;
use App\Models\Product;
use App\Models\User;
use App\Support\BusinessFeaturePresets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Central Cell: celular de $500.000, el cliente da $100.000 de inicial y
 * Banti financia $400.000 que luego le gira al negocio.
 */
class FinancingTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: Business, 1: User, 2: FinancingProvider, 3: Product} */
    private function scenario(bool $enabled = true): array
    {
        $business = Business::factory()->create(['feature_flags' => array_merge(BusinessFeaturePresets::full(), ['financing' => $enabled])]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $provider = FinancingProvider::create(['business_id' => $business->id, 'name' => 'Banti', 'expected_payout_days' => 3]);
        $phone = Product::factory()->create(['business_id' => $business->id, 'price' => 500000, 'track_stock' => true, 'stock' => 5]);

        return [$business, $user, $provider, $phone];
    }

    private function financedSale(User $user, FinancingProvider $provider, Product $phone, array $overrides = []): TestResponse
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', array_merge([
            'payment_method' => 'cash',
            'customer_name' => 'Laura Gómez',
            'items' => [['product_id' => $phone->id, 'quantity' => 1]],
            'financing' => ['financing_provider_id' => $provider->id, 'amount' => 400000, 'approval_number' => 'BT-981'],
        ], $overrides));
    }

    public function test_a_financed_sale_splits_the_payment_and_leaves_a_credit_with_the_provider(): void
    {
        [, $user, $provider, $phone] = $this->scenario();

        $saleId = $this->financedSale($user, $provider, $phone)->assertCreated()->json('id');

        $this->assertDatabaseHas('sale_payment_splits', ['sale_id' => $saleId, 'payment_method' => 'cash', 'amount' => 100000]);
        $this->assertDatabaseHas('sale_payment_splits', ['sale_id' => $saleId, 'payment_method' => 'financiado', 'amount' => 400000, 'payer_label' => 'Banti']);
        $this->assertDatabaseHas('financing_credits', [
            'sale_id' => $saleId,
            'financing_provider_id' => $provider->id,
            'amount' => 400000,
            'approval_number' => 'BT-981',
            'customer_name' => 'Laura Gómez',
            'status' => 'pending',
            'expected_payout_date' => now()->addDays(3)->toDateString(),
        ]);
        $this->assertSame(4, $phone->fresh()->stock);
    }

    /** Solo la inicial es plata en caja: los $400.000 los gira Banti despues. */
    public function test_only_the_down_payment_counts_as_cash(): void
    {
        [, $user, $provider, $phone] = $this->scenario();
        $this->financedSale($user, $provider, $phone)->assertCreated();

        $totals = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/cash-closings/preview?date='.now()->toDateString().'&opening_cash=0')
            ->assertOk()
            ->json('totals');

        $this->assertEquals(500000, $totals['total_sales']);
        $this->assertEquals(100000, $totals['total_cash']);
        $this->assertEquals(100000, $totals['expected_cash']);
        $financed = collect($totals['payment_breakdown'])->firstWhere('id', 'financiado');
        $this->assertEquals(400000, $financed['total']);
        $this->assertSame('Financiado', $financed['label']);
    }

    public function test_a_fully_financed_sale_needs_no_down_payment_method(): void
    {
        [, $user, $provider, $phone] = $this->scenario();

        $this->financedSale($user, $provider, $phone, [
            'payment_method' => null,
            'financing' => ['financing_provider_id' => $provider->id, 'amount' => 500000],
        ])->assertCreated()->assertJsonPath('payment_method', 'financiado');
    }

    public function test_a_financed_sale_is_rejected_when_it_cannot_be_collected(): void
    {
        [, $user, $provider, $phone] = $this->scenario();

        $this->financedSale($user, $provider, $phone, ['customer_name' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_name');

        $this->financedSale($user, $provider, $phone, ['financing' => ['financing_provider_id' => $provider->id, 'amount' => 600000]])
            ->assertUnprocessable()->assertJsonValidationErrors('financing.amount');

        $this->financedSale($user, $provider, $phone, ['payment_method' => 'credit'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('financing_credits', 0);
    }

    public function test_the_feature_must_be_enabled(): void
    {
        [, $user, $provider, $phone] = $this->scenario(enabled: false);

        $this->financedSale($user, $provider, $phone)->assertUnprocessable()->assertJsonValidationErrors('financing');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/financing/providers')->assertForbidden();
    }

    public function test_registering_the_payout_and_the_summary_per_provider(): void
    {
        [, $user, $provider, $phone] = $this->scenario();
        $this->financedSale($user, $provider, $phone)->assertCreated();
        $this->financedSale($user, $provider, $phone)->assertCreated();
        $credit = FinancingCredit::latest('id')->first();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/financing/credits/{$credit->id}/payout", ['payout_method' => 'transferencia', 'payout_reference' => 'TRX-55'])
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('payout_reference', 'TRX-55');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/financing/credits/{$credit->id}/payout")
            ->assertUnprocessable();

        $this->travel(5)->days();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/financing/summary')
            ->assertOk()
            ->assertJsonPath('pending_amount', 400000)
            ->assertJsonPath('overdue_amount', 400000)
            ->assertJsonPath('providers.0.pending_count', 1);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/financing/credits?status=overdue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_overdue', true);
    }

    public function test_reversing_a_sale_drops_a_pending_credit_but_not_a_paid_one(): void
    {
        [, $user, $provider, $phone] = $this->scenario();
        $pendingSaleId = $this->financedSale($user, $provider, $phone)->json('id');
        $paidSaleId = $this->financedSale($user, $provider, $phone)->json('id');
        $paidCredit = FinancingCredit::where('sale_id', $paidSaleId)->first();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/financing/credits/{$paidCredit->id}/payout")->assertOk();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/sales/{$pendingSaleId}/reverse")->assertNoContent();
        $this->assertDatabaseMissing('financing_credits', ['sale_id' => $pendingSaleId]);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/sales/{$paidSaleId}/reverse")->assertUnprocessable();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/financing/credits/{$paidCredit->id}/undo-payout")
            ->assertOk()->assertJsonPath('status', 'pending');
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/sales/{$paidSaleId}/reverse")->assertNoContent();
    }

    public function test_cashiers_see_active_providers_but_cannot_register_payouts(): void
    {
        [$business, , $provider] = $this->scenario();
        FinancingProvider::create(['business_id' => $business->id, 'name' => 'Addi', 'is_active' => false]);
        $cashier = User::factory()->create(['business_id' => $business->id]);
        $cashier->assignRole('employee');
        $credit = FinancingCredit::create(['business_id' => $business->id, 'financing_provider_id' => $provider->id, 'amount' => 1000, 'status' => 'pending']);

        $this->actingAs($cashier, 'sanctum')->getJson('/api/v1/financing/providers?active_only=1')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Banti');

        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/financing/credits/{$credit->id}/payout")->assertForbidden();
    }

    public function test_the_admin_manages_providers(): void
    {
        [, $user, $provider] = $this->scenario();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/financing/providers', ['name' => 'Addi', 'expected_payout_days' => 2])
            ->assertCreated()->assertJsonPath('name', 'Addi');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/financing/providers', ['name' => 'Banti'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/financing/providers/{$provider->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);
    }
}
