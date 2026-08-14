<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Discount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DiscountTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_list_only_their_business_discounts(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Discount::factory()->count(2)->create(['business_id' => $business->id]);
        Discount::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/discounts')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_active_only_filter_excludes_inactive_discounts(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Discount::factory()->create(['business_id' => $business->id, 'is_active' => true]);
        Discount::factory()->create(['business_id' => $business->id, 'is_active' => false]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/discounts?active_only=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_per_page_above_the_cap_is_clamped_to_200(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/discounts?per_page=9999')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 200);
    }

    public function test_user_can_create_a_percentage_discount(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/discounts', [
            'name' => 'Descuento Fiesta',
            'type' => 'percentage',
            'value' => 15,
            'scope' => 'cart',
        ]);

        $response->assertCreated()->assertJsonPath('name', 'Descuento Fiesta');
    }

    public function test_discount_name_must_be_unique_within_the_same_business(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        Discount::factory()->create(['business_id' => $business->id, 'name' => 'Repetido']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/discounts', [
                'name' => 'Repetido',
                'type' => 'fixed',
                'value' => 1000,
                'scope' => 'item',
            ])
            ->assertStatus(422);
    }

    public function test_discount_cannot_reference_a_product_from_another_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreignProduct = Product::factory()->create(['business_id' => $otherBusiness->id]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/discounts', [
                'name' => 'Descuento Producto',
                'type' => 'fixed',
                'value' => 1000,
                'scope' => 'item',
                'product_id' => $foreignProduct->id,
            ])
            ->assertStatus(422);
    }

    public function test_percentage_discount_value_cannot_exceed_100(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/discounts', [
                'name' => 'Descuento invalido',
                'type' => 'percentage',
                'value' => 150,
                'scope' => 'cart',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');
    }

    public function test_fixed_discount_value_can_exceed_100(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/discounts', [
                'name' => 'Descuento fijo grande',
                'type' => 'fixed',
                'value' => 150000,
                'scope' => 'cart',
            ])
            ->assertCreated();
    }

    public function test_updating_a_percentage_discount_value_above_100_is_rejected_using_stored_type(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $discount = Discount::factory()->create(['business_id' => $business->id, 'type' => 'percentage', 'value' => 20]);

        // No manda "type" en el update - debe seguir usando el limite de 100
        // basado en el type ya guardado en BD, no dejar pasar cualquier valor
        // solo porque el campo no vino en este request puntual.
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/discounts/{$discount->id}", ['value' => 200])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');
    }

    public function test_compute_amount_caps_percentage_discount_at_the_subtotal(): void
    {
        $discount = Discount::factory()->create(['type' => 'percentage', 'value' => 200]);

        $this->assertSame(1000.0, $discount->computeAmount(1000));
    }

    public function test_compute_amount_for_a_fixed_discount(): void
    {
        $discount = Discount::factory()->fixed()->create(['value' => 5000]);

        $this->assertSame(5000.0, $discount->computeAmount(20000));
        $this->assertSame(3000.0, $discount->computeAmount(3000));
    }

    public function test_user_cannot_view_a_discount_from_another_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $discount = Discount::factory()->create(['business_id' => $otherBusiness->id]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/discounts/{$discount->id}")
            ->assertNotFound();
    }

    public function test_user_can_update_and_delete_a_discount(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $discount = Discount::factory()->create(['business_id' => $business->id, 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/discounts/{$discount->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/discounts/{$discount->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('discounts', ['id' => $discount->id]);
    }

    public function test_created_discount_response_reflects_the_database_default_is_active(): void
    {
        // Bug real: store() no refrescaba de BD, asi que "is_active" (DEFAULT 1)
        // llegaba null al cliente si el request no lo mandaba.
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/discounts', [
                'name' => 'Sin is_active explicito',
                'type' => 'fixed',
                'value' => 1000,
                'scope' => 'cart',
            ])
            ->assertCreated()
            ->assertJsonPath('is_active', true);
    }
}
