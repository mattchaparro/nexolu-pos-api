<?php

namespace Tests\Feature\Api\V1;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_create_an_appointment_with_a_single_service_and_it_creates_a_linked_order(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $service = Product::factory()->service()->create(['business_id' => $business->id, 'price' => 40000]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $service->id]],
            'client_name' => 'Ana Gomez',
            'client_phone' => '3001112233',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('client_name', 'Ana Gomez')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('service_order.total', '40000.00')
            ->assertJsonPath('service_order.status', 'pending');

        $this->assertDatabaseHas('service_orders', ['appointment_id' => $response->json('id'), 'total' => 40000]);
    }

    public function test_a_non_utc_offset_in_starts_at_is_normalized_instead_of_shifting_the_instant(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $service = Product::factory()->service()->create(['business_id' => $business->id]);

        // "15:00 -05:00" es el mismo instante que "20:00 UTC" - antes de la
        // normalizacion en AppointmentService::parseUtc(), Carbon::parse()
        // guardaba la hora tal cual (15:00) re-etiquetada como UTC, un
        // corrimiento silencioso de 5 horas.
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $service->id]],
            'client_name' => 'Ana Gomez',
            'starts_at' => '2026-08-13T15:00:00-05:00',
            'ends_at' => '2026-08-13T15:45:00-05:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('starts_at', '2026-08-13T20:00:00+00:00')
            ->assertJsonPath('ends_at', '2026-08-13T20:45:00+00:00');

        $this->assertDatabaseHas('appointments', [
            'id' => $response->json('id'),
            'starts_at' => '2026-08-13 20:00:00',
        ]);
    }

    public function test_creating_an_appointment_with_multiple_services_itemizes_the_linked_order(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $serviceA = Product::factory()->service()->create(['business_id' => $business->id, 'price' => 20000]);
        $serviceB = Product::factory()->service()->create(['business_id' => $business->id, 'price' => 15000]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $serviceA->id], ['id' => $serviceB->id]],
            'client_name' => 'Carlos Ruiz',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('service_order.total', '35000.00')
            ->assertJsonCount(2, 'service_order.items');
    }

    public function test_an_initial_payment_can_complete_the_linked_order_and_the_appointment(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $service = Product::factory()->service()->create(['business_id' => $business->id, 'price' => 40000]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $service->id]],
            'client_name' => 'Ana Gomez',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'initial_payment' => 40000,
            'payment_method' => 'cash',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('service_order.status', 'paid');
    }

    public function test_double_booking_the_same_staff_member_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $staff = User::factory()->create(['business_id' => $business->id]);
        $service = Product::factory()->service()->create(['business_id' => $business->id]);

        $starts = now()->addDay()->setTime(10, 0);
        Appointment::factory()->create([
            'business_id' => $business->id,
            'user_id' => $staff->id,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHour(),
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $service->id]],
            'user_id' => $staff->id,
            'client_name' => 'Otro Cliente',
            'starts_at' => $starts->copy()->addMinutes(30)->toIso8601String(),
            'ends_at' => $starts->copy()->addMinutes(90)->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors(['starts_at']);
    }

    public function test_a_non_overlapping_appointment_for_the_same_staff_member_is_allowed(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $staff = User::factory()->create(['business_id' => $business->id]);
        $service = Product::factory()->service()->create(['business_id' => $business->id]);

        $starts = now()->addDay()->setTime(10, 0);
        Appointment::factory()->create([
            'business_id' => $business->id,
            'user_id' => $staff->id,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHour(),
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $service->id]],
            'user_id' => $staff->id,
            'client_name' => 'Otro Cliente',
            'starts_at' => $starts->copy()->addHour()->toIso8601String(),
            'ends_at' => $starts->copy()->addHours(2)->toIso8601String(),
        ])->assertCreated();
    }

    public function test_a_cancelled_appointment_does_not_count_towards_double_booking(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $staff = User::factory()->create(['business_id' => $business->id]);
        $service = Product::factory()->service()->create(['business_id' => $business->id]);

        $starts = now()->addDay()->setTime(10, 0);
        Appointment::factory()->cancelled()->create([
            'business_id' => $business->id,
            'user_id' => $staff->id,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHour(),
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $service->id]],
            'user_id' => $staff->id,
            'client_name' => 'Otro Cliente',
            'starts_at' => $starts->copy()->toIso8601String(),
            'ends_at' => $starts->copy()->addHour()->toIso8601String(),
        ])->assertCreated();
    }

    public function test_updating_a_completed_appointment_is_rejected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $service = Product::factory()->service()->create(['business_id' => $business->id]);
        $appointment = Appointment::factory()->create(['business_id' => $business->id, 'status' => 'completed']);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/appointments/{$appointment->id}", [
            'services' => [['id' => $service->id]],
            'client_name' => 'x',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_reschedule_updates_the_time_and_resets_status_to_pending(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $appointment = Appointment::factory()->create(['business_id' => $business->id, 'status' => 'confirmed']);

        $newStart = now()->addDays(3)->setTime(14, 0);
        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/appointments/{$appointment->id}/reschedule", [
            'starts_at' => $newStart->toIso8601String(),
            'ends_at' => $newStart->copy()->addHour()->toIso8601String(),
        ]);

        $response->assertOk()->assertJsonPath('status', 'pending');
    }

    public function test_cancelling_an_appointment_also_cancels_and_refunds_its_linked_order(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $service = Product::factory()->service()->create(['business_id' => $business->id, 'price' => 40000]);

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $service->id]],
            'client_name' => 'Ana Gomez',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'initial_payment' => 40000,
            'payment_method' => 'cash',
        ])->json();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/appointments/{$created['id']}/status", ['status' => 'cancelled']);

        $response->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('service_orders', [
            'appointment_id' => $created['id'], 'status' => 'cancelled', 'amount_paid' => 0,
        ]);
        $this->assertDatabaseHas('service_payments', [
            'amount' => -40000,
            'payment_method' => 'cash',
        ]);
    }

    public function test_user_can_only_see_their_business_appointments(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Appointment::factory()->count(2)->create(['business_id' => $business->id]);
        Appointment::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/appointments')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
