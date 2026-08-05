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
}
