<?php

namespace Tests\Feature\Api\V1;

use App\Models\BillingProfile;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cubre GET/PUT /v1/business/billing-profile - un unico perfil de
 * facturacion por negocio (nunca por usuario), todos los campos opcionales,
 * consumido por PseModal.vue para prellenar y por el paso opcional del
 * wizard de registro.
 */
class BillingProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_show_returns_an_empty_profile_when_none_exists_yet(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/business/billing-profile');

        $response->assertOk()->assertJson([
            'document_type' => null,
            'document_number' => null,
            'full_name' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'city' => null,
        ]);
    }

    public function test_show_returns_the_saved_profile(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        BillingProfile::factory()->for($business)->create(['full_name' => 'Cliente De Prueba']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/business/billing-profile');

        $response->assertOk()->assertJsonPath('full_name', 'Cliente De Prueba');
    }

    public function test_update_creates_the_profile_when_it_does_not_exist(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/v1/business/billing-profile', [
            'document_type' => 'CC',
            'document_number' => '1099888777',
            'full_name' => 'Cliente De Prueba',
            'phone' => '3107654321',
        ]);

        // 201, no 200: Laravel devuelve 201 automaticamente cuando el
        // recurso subyacente wasRecentlyCreated - mismo criterio que
        // FixedExpenseTemplateController::store() en este repo.
        $response->assertStatus(201)->assertJsonPath('document_number', '1099888777');
        $this->assertDatabaseHas('billing_profiles', [
            'business_id' => $business->id,
            'document_number' => '1099888777',
        ]);
    }

    public function test_update_overwrites_the_existing_profile_instead_of_duplicating_it(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        BillingProfile::factory()->for($business)->create(['full_name' => 'Nombre Viejo']);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/business/billing-profile', [
            'full_name' => 'Nombre Nuevo',
        ])->assertOk()->assertJsonPath('full_name', 'Nombre Nuevo');

        $this->assertDatabaseCount('billing_profiles', 1);
    }

    public function test_update_rejects_an_invalid_document_type(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/v1/business/billing-profile', [
            'document_type' => 'PASSPORT',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.document_type.0', 'El tipo de documento debe ser CC, NIT o CE.');
    }

    public function test_a_users_billing_profile_is_scoped_to_their_own_business(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $otherProfile = BillingProfile::factory()->for(Business::factory())->create(['full_name' => 'Otro Negocio']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/business/billing-profile');

        $response->assertOk()->assertJsonMissing(['full_name' => 'Otro Negocio']);
        $this->assertNotEquals($otherProfile->business_id, $business->id);
    }
}
