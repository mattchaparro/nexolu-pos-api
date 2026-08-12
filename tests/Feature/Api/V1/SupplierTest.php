<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_list_only_their_business_suppliers(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Supplier::factory()->count(2)->create(['business_id' => $business->id]);
        Supplier::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/suppliers')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_user_can_create_a_supplier(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/suppliers', [
            'name' => 'Distribuidora ABC',
            'phone' => '3001234567',
        ]);

        $response->assertCreated()->assertJsonPath('name', 'Distribuidora ABC');
        $this->assertDatabaseHas('suppliers', ['name' => 'Distribuidora ABC', 'business_id' => $business->id]);
    }

    public function test_user_cannot_view_a_supplier_from_another_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $supplier = Supplier::factory()->create(['business_id' => $otherBusiness->id]);

        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/suppliers/{$supplier->id}")
            ->assertNotFound();
    }

    public function test_suppliers_module_is_blocked_without_an_eligible_feature_flag(): void
    {
        $business = Business::factory()->create([
            'feature_flags' => ['inventory' => false, 'inventory_advanced' => false, 'ingredients' => false],
        ]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/suppliers')
            ->assertForbidden();
    }

    public function test_suppliers_module_is_allowed_with_basic_inventory(): void
    {
        $business = Business::factory()->create([
            'feature_flags' => ['inventory' => true, 'inventory_advanced' => false, 'ingredients' => false],
        ]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/suppliers')
            ->assertOk();
    }

    public function test_user_can_update_and_delete_a_supplier(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $supplier = Supplier::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/suppliers/{$supplier->id}", ['name' => 'Nombre Actualizado'])
            ->assertOk()
            ->assertJsonPath('name', 'Nombre Actualizado');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/suppliers/{$supplier->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
    }

    public function test_user_can_create_a_visit_reminder_for_a_supplier(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $supplier = Supplier::factory()->create(['business_id' => $business->id, 'name' => 'Postobón']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/suppliers/{$supplier->id}/remind-visit", [
            'due_date' => now()->addDay()->toDateString(),
            'recurrence' => 'weekly',
        ]);

        $response->assertCreated()->assertJsonPath('title', 'Visita de Postobón');

        $this->assertDatabaseHas('reminders', [
            'remindable_type' => Supplier::class,
            'remindable_id' => $supplier->id,
            'title' => 'Visita de Postobón',
            'recurrence' => 'weekly',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/suppliers/{$supplier->id}")
            ->assertOk();
    }

    public function test_remind_visit_requires_a_due_date(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $supplier = Supplier::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/suppliers/{$supplier->id}/remind-visit", [])
            ->assertStatus(422);
    }

    public function test_index_flags_suppliers_with_a_pending_visit_reminder(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $supplier = Supplier::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/suppliers/{$supplier->id}/remind-visit", [
            'due_date' => now()->addDay()->toDateString(),
        ])->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/suppliers')
            ->assertOk()
            ->assertJsonPath('data.0.has_pending_visit_reminder', true);
    }

    public function test_index_exposes_the_nearest_pending_reminder_due_date(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $supplier = Supplier::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/suppliers/{$supplier->id}/remind-visit", [
            'due_date' => now()->addDays(5)->toDateString(),
        ])->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/suppliers/{$supplier->id}/remind-visit", [
            'due_date' => now()->addDay()->toDateString(),
        ])->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/suppliers')
            ->assertOk()
            ->assertJsonPath('data.0.next_visit_reminder_due_date', now()->addDay()->toDateString());
    }
}
