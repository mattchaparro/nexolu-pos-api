<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class KitchenBoardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_index_lists_open_tickets_with_items(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $product = Product::factory()->create(['business_id' => $business->id, 'name' => 'Hamburguesa']);
        $sale = Sale::factory()->create(['business_id' => $business->id, 'status' => 'open', 'customer_name' => 'Mesa 3']);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 2]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/kitchen/tickets');

        $response->assertOk()->assertJsonCount(1);
        $response->assertJsonPath('0.customer_name', 'Mesa 3');
        $response->assertJsonPath('0.kitchen_status', 'pending');
        $response->assertJsonPath('0.items.0.name', 'Hamburguesa');
        $response->assertJsonPath('0.items.0.quantity', 2);
    }

    public function test_an_employee_can_update_ticket_status_without_a_specific_permission(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');
        $product = Product::factory()->create(['business_id' => $business->id]);
        $sale = Sale::factory()->create(['business_id' => $business->id, 'status' => 'open']);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $product->id]);

        $response = $this->actingAs($employee, 'sanctum')
            ->postJson("/api/v1/kitchen/tickets/{$sale->id}/status", ['kitchen_status' => 'ready']);

        $response->assertOk()->assertJsonPath('kitchen_status', 'ready');
        $this->assertSame('ready', $sale->fresh()->kitchen_status);
    }

    public function test_update_status_rejects_an_invalid_status(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $sale = Sale::factory()->create(['business_id' => $business->id, 'status' => 'open']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/kitchen/tickets/{$sale->id}/status", ['kitchen_status' => 'served'])
            ->assertStatus(422);
    }

    public function test_a_sale_from_another_business_is_not_reachable(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $otherBusiness = Business::factory()->create();
        $foreignSale = Sale::factory()->create(['business_id' => $otherBusiness->id, 'status' => 'open']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/kitchen/tickets/{$foreignSale->id}/status", ['kitchen_status' => 'ready'])
            ->assertStatus(404);
    }

    public function test_a_business_without_the_feature_is_blocked(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['kitchen_board' => false]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/kitchen/tickets')
            ->assertStatus(403);
    }
}
