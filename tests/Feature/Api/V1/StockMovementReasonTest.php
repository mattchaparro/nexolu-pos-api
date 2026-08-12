<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\StockMovementReason;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StockMovementReasonTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_list_the_global_stock_movement_reasons(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/stock-movement-reasons');

        $response->assertOk();
        $codes = collect($response->json())->pluck('code');
        $this->assertContains(StockMovementReason::CODE_MANUAL_IN, $codes);
        $this->assertContains(StockMovementReason::CODE_WASTE, $codes);
        $this->assertContains(StockMovementReason::CODE_DAMAGE, $codes);
        $this->assertContains(StockMovementReason::CODE_ADJUSTMENT, $codes);
    }

    public function test_a_per_business_reason_does_not_leak_into_the_global_list(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        StockMovementReason::create([
            'business_id' => $business->id,
            'code' => 'custom_reason',
            'label' => 'Motivo propio del negocio',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/stock-movement-reasons');

        $response->assertOk();
        $codes = collect($response->json())->pluck('code');
        $this->assertNotContains('custom_reason', $codes);
    }
}
