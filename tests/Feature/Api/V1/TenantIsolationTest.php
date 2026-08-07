<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Client;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_belongs_to_business_forces_the_authenticated_users_business_id(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum');

        // Aunque se intente inyectar el business_id de otro tenant, el trait lo sobrescribe.
        $client = Client::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Cliente inyectado',
        ]);

        $this->assertSame($business->id, $client->business_id);
    }

    public function test_belongs_to_business_respects_explicit_business_id_without_auth(): void
    {
        $business = Business::factory()->create();

        // Sin usuario autenticado (seeders/jobs), el business_id explicito se respeta.
        $category = ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Categoria seeder',
        ]);

        $this->assertSame($business->id, $category->business_id);
    }
}
