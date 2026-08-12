<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_view_their_business(): void
    {
        $business = Business::factory()->create(['name' => 'Cafe Nexolu']);
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('id', $business->id)
            ->assertJsonPath('name', 'Cafe Nexolu');
    }

    public function test_business_resource_exposes_computed_can_access_purchases(): void
    {
        $withoutFlags = Business::factory()->create(['feature_flags' => null]);
        $ownerWithout = User::factory()->create(['business_id' => $withoutFlags->id, 'is_business_owner' => true]);

        $this->actingAs($ownerWithout, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('can_access_purchases', true);

        $blocked = Business::factory()->create([
            'feature_flags' => ['inventory' => false, 'inventory_advanced' => false, 'ingredients' => false],
        ]);
        $ownerBlocked = User::factory()->create(['business_id' => $blocked->id, 'is_business_owner' => true]);

        $this->actingAs($ownerBlocked, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('can_access_purchases', false);
    }

    public function test_business_resource_exposes_computed_can_access_services(): void
    {
        $enabled = Business::factory()->create(['feature_flags' => ['services' => true]]);
        $ownerEnabled = User::factory()->create(['business_id' => $enabled->id, 'is_business_owner' => true]);

        $this->actingAs($ownerEnabled, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('can_access_services', true);

        $disabled = Business::factory()->create(['feature_flags' => ['services' => false]]);
        $ownerDisabled = User::factory()->create(['business_id' => $disabled->id, 'is_business_owner' => true]);

        $this->actingAs($ownerDisabled, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('can_access_services', false);
    }

    public function test_business_resource_exposes_computed_can_access_layaways(): void
    {
        $enabled = Business::factory()->create(['feature_flags' => ['layaway' => true]]);
        $ownerEnabled = User::factory()->create(['business_id' => $enabled->id, 'is_business_owner' => true]);

        $this->actingAs($ownerEnabled, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('can_access_layaways', true);

        $disabled = Business::factory()->create(['feature_flags' => ['layaway' => false]]);
        $ownerDisabled = User::factory()->create(['business_id' => $disabled->id, 'is_business_owner' => true]);

        $this->actingAs($ownerDisabled, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('can_access_layaways', false);
    }

    public function test_owner_can_update_their_business(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/business', ['name' => 'Nuevo Nombre'])
            ->assertOk()
            ->assertJsonPath('name', 'Nuevo Nombre');

        $this->assertSame('Nuevo Nombre', $business->fresh()->name);
    }

    public function test_regular_employee_cannot_update_the_business(): void
    {
        $business = Business::factory()->create(['name' => 'Original']);
        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);

        $this->actingAs($employee, 'sanctum')
            ->putJson('/api/v1/business', ['name' => 'Hackeado'])
            ->assertForbidden();

        $this->assertSame('Original', $business->fresh()->name);
    }

    public function test_user_without_a_business_gets_not_found(): void
    {
        $user = User::factory()->create(['business_id' => null]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/business')
            ->assertNotFound();
    }
}
