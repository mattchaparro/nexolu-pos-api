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
