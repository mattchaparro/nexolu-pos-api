<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\Business;
use App\Models\PosPaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class PosPaymentMethodsTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_full_crud_lifecycle_without_destroy(): void
    {
        $admin = $this->superadmin();

        $store = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/pos-payment-methods', [
            'key' => 'nequi',
            'label' => 'Nequi',
        ]);
        $store->assertCreated()->assertJsonPath('key', 'nequi')->assertJsonPath('is_active', true);

        $id = $store->json('id');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/pos-payment-methods')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.businesses_count', 0);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/superadmin/pos-payment-methods/{$id}", [
                'label' => 'Nequi (billetera)',
                'is_active' => false,
                'sort_order' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('label', 'Nequi (billetera)')
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('sort_order', 5)
            // el 'key' no es editable, se ignora si viene en el payload
            ->assertJsonPath('key', 'nequi');
    }

    public function test_key_must_be_a_normalized_slug_and_unique(): void
    {
        $admin = $this->superadmin();
        PosPaymentMethod::factory()->create(['key' => 'cash']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/pos-payment-methods', [
            'key' => 'Con Espacios',
            'label' => 'Invalido',
        ])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/pos-payment-methods', [
            'key' => 'cash',
            'label' => 'Duplicado',
        ])->assertStatus(422);
    }

    public function test_there_is_no_destroy_route(): void
    {
        $admin = $this->superadmin();
        $method = PosPaymentMethod::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/superadmin/pos-payment-methods/{$method->id}")
            ->assertStatus(405);
    }

    public function test_regular_business_user_cannot_manage_the_catalog(): void
    {
        PosPaymentMethod::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/superadmin/pos-payment-methods')
            ->assertForbidden();
    }

    public function test_businesses_count_reflects_how_many_businesses_selected_it(): void
    {
        $admin = $this->superadmin();
        $method = PosPaymentMethod::factory()->create();
        $business = Business::factory()->create();
        $business->posPaymentMethods()->attach($method->id, ['is_enabled' => true]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/pos-payment-methods')
            ->assertOk()
            ->assertJsonPath('0.businesses_count', 1);
    }
}
