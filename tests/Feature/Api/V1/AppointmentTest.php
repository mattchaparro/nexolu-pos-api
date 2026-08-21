<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\SendAppointmentConfirmationJob;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\BusinessServiceWorkflow;
use App\Models\Product;
use App\Models\Reminder;
use App\Models\ServiceWorkflow;
use App\Models\ServiceWorkflowStage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
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

    public function test_creating_an_appointment_with_a_phone_queues_the_whatsapp_confirmation_and_a_two_hour_reminder(): void
    {
        Queue::fake();
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $service = Product::factory()->service()->create(['business_id' => $business->id, 'price' => 40000]);
        $starts = now()->addDay()->setTime(15, 0);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $service->id]],
            'client_name' => 'Ana Gomez',
            'client_phone' => '3001112233',
            'starts_at' => $starts->toIso8601String(),
            'ends_at' => $starts->copy()->addHour()->toIso8601String(),
        ]);
        $appointmentId = $response->json('id');

        Queue::assertPushed(SendAppointmentConfirmationJob::class, fn ($job) => $job->appointmentId === $appointmentId);

        $reminder = Reminder::where('remindable_type', Appointment::class)->where('remindable_id', $appointmentId)->first();
        $this->assertNotNull($reminder);
        $this->assertSame(Reminder::STATUS_PENDING, $reminder->status);
        $this->assertFalse((bool) $reminder->notify_whatsapp);
        $expected = $starts->copy()->subHours(2)->timezone('America/Bogota');
        $this->assertSame($expected->toDateString(), $reminder->due_date->toDateString());
        $this->assertSame($expected->format('H:i'), substr((string) $reminder->notify_time, 0, 5));
    }

    public function test_creating_an_appointment_without_a_phone_does_not_queue_anything(): void
    {
        Queue::fake();
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $service = Product::factory()->service()->create(['business_id' => $business->id, 'price' => 40000]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $service->id]],
            'client_name' => 'Ana Gomez',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ]);

        Queue::assertNotPushed(SendAppointmentConfirmationJob::class);
        $this->assertDatabaseMissing('reminders', ['remindable_type' => Appointment::class, 'remindable_id' => $response->json('id')]);
    }

    public function test_the_linked_order_stage_is_exposed_when_the_business_has_a_workflow_assigned(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $service = Product::factory()->service()->create(['business_id' => $business->id, 'price' => 40000]);

        $workflow = ServiceWorkflow::factory()->create();
        $initial = ServiceWorkflowStage::factory()->create(['workflow_id' => $workflow->id, 'label' => 'Recibido', 'is_initial' => true]);
        BusinessServiceWorkflow::create(['business_id' => $business->id, 'workflow_id' => $workflow->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $service->id]],
            'client_name' => 'Ana Gomez',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('service_order.stage_id', $initial->id)
            ->assertJsonPath('service_order.stage.label', 'Recibido');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/appointments/{$response->json('id')}")
            ->assertOk()
            ->assertJsonPath('service_order.stage.id', $initial->id);
    }

    public function test_a_utc_offset_in_starts_at_is_normalized_to_bogota_instead_of_shifting_the_instant(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $service = Product::factory()->service()->create(['business_id' => $business->id]);

        // "20:00 UTC" es el mismo instante que "15:00 -05:00" (Bogota) -
        // antes de la normalizacion en AppointmentService::parseLocal(),
        // Carbon::parse() guardaba la hora tal cual (20:00) re-etiquetada
        // como si ya fuera Bogota, un corrimiento silencioso de 5 horas.
        // Se normaliza a Bogota, no a UTC (ver el docblock de
        // parseLocal()): `starts_at`/`ends_at` son columnas `datetime`
        // compartidas con pos-saas-legacy, que siempre escribio hora
        // literal de Bogota ahi.
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $service->id]],
            'client_name' => 'Ana Gomez',
            'starts_at' => '2026-08-13T20:00:00Z',
            'ends_at' => '2026-08-13T20:45:00Z',
        ]);

        $response->assertCreated()
            ->assertJsonPath('starts_at', '2026-08-13T15:00:00-05:00')
            ->assertJsonPath('ends_at', '2026-08-13T15:45:00-05:00');

        $this->assertDatabaseHas('appointments', [
            'id' => $response->json('id'),
            'starts_at' => '2026-08-13 15:00:00',
            'ends_at' => '2026-08-13 15:45:00',
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

    public function test_reschedule_updates_the_pending_two_hour_reminder_to_match_the_new_time(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $service = Product::factory()->service()->create(['business_id' => $business->id, 'price' => 40000]);
        $originalStart = now()->addDay()->setTime(15, 0);

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $service->id]],
            'client_name' => 'Ana Gomez',
            'client_phone' => '3001112233',
            'starts_at' => $originalStart->toIso8601String(),
            'ends_at' => $originalStart->copy()->addHour()->toIso8601String(),
        ])->json();

        $newStart = now()->addDays(3)->setTime(18, 0);
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/appointments/{$created['id']}/reschedule", [
            'starts_at' => $newStart->toIso8601String(),
            'ends_at' => $newStart->copy()->addHour()->toIso8601String(),
        ])->assertOk();

        $reminder = Reminder::where('remindable_type', Appointment::class)->where('remindable_id', $created['id'])->first();
        $expected = $newStart->copy()->subHours(2)->timezone('America/Bogota');
        $this->assertSame($expected->toDateString(), $reminder->fresh()->due_date->toDateString());
        $this->assertSame($expected->format('H:i'), substr((string) $reminder->fresh()->notify_time, 0, 5));
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

    public function test_cancelling_an_appointment_deletes_its_pending_two_hour_reminder(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $service = Product::factory()->service()->create(['business_id' => $business->id, 'price' => 40000]);

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $service->id]],
            'client_name' => 'Ana Gomez',
            'client_phone' => '3001112233',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/appointments/{$created['id']}/status", ['status' => 'cancelled'])
            ->assertOk();

        $this->assertDatabaseMissing('reminders', ['remindable_type' => Appointment::class, 'remindable_id' => $created['id']]);
    }

    /**
     * 'to' es una fecha sin hora (limite del rango visible del calendario)
     * - una cita que empieza a las 6pm de ese mismo dia debe seguir
     * contando como parte del rango, no quedar cortada en la medianoche.
     */
    public function test_the_to_filter_includes_the_whole_day_not_just_midnight(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $lateAppointment = Appointment::factory()->create([
            'business_id' => $business->id,
            'starts_at' => '2026-08-16 18:00:00',
            'ends_at' => '2026-08-16 19:00:00',
        ]);
        $nextDayAppointment = Appointment::factory()->create([
            'business_id' => $business->id,
            'starts_at' => '2026-08-17 09:00:00',
            'ends_at' => '2026-08-17 10:00:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/appointments?from=2026-08-10&to=2026-08-16')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($lateAppointment->id));
        $this->assertFalse($ids->contains($nextDayAppointment->id));
    }

    public function test_per_page_can_be_raised_above_the_default_for_the_month_view(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        Appointment::factory()->count(60)->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/appointments?per_page=100')
            ->assertOk()
            ->assertJsonCount(60, 'data');
    }

    public function test_per_page_above_the_cap_is_clamped_to_200(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/appointments?per_page=9999')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 200);
    }

    public function test_user_can_delete_an_appointment(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $appointment = Appointment::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/appointments/{$appointment->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('appointments', ['id' => $appointment->id]);
    }

    /**
     * Appointment usa SoftDeletes - eliminar la cita es un UPDATE
     * (deleted_at), no un DELETE real, así que el ON DELETE SET NULL de
     * service_orders.appointment_id nunca se dispara. La orden sigue
     * intacta y vinculada, solo la cita desaparece del calendario.
     */
    public function test_deleting_an_appointment_does_not_delete_its_linked_order(): void
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
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/appointments/{$created['id']}")
            ->assertNoContent();

        $this->assertDatabaseHas('service_orders', ['appointment_id' => $created['id'], 'total' => 40000]);
    }

    public function test_deleting_an_appointment_deletes_its_pending_two_hour_reminder(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $service = Product::factory()->service()->create(['business_id' => $business->id, 'price' => 40000]);

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/appointments', [
            'services' => [['id' => $service->id]],
            'client_name' => 'Ana Gomez',
            'client_phone' => '3001112233',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ])->json();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/appointments/{$created['id']}")
            ->assertNoContent();

        $this->assertDatabaseMissing('reminders', ['remindable_type' => Appointment::class, 'remindable_id' => $created['id']]);
    }

    public function test_user_cannot_delete_another_businesss_appointment(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');
        $otherBusiness = Business::factory()->create();
        $appointment = Appointment::factory()->create(['business_id' => $otherBusiness->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/appointments/{$appointment->id}")
            ->assertNotFound();
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

    /**
     * 'services' (productos/ordenes de servicio) y 'scheduling' (Agenda)
     * son features independientes - un negocio puede vender servicios sin
     * necesitar calendario (ej. reparaciones a domicilio). Antes compartian
     * un solo flag 'services' para los dos, asi que un negocio sin agenda
     * igual podia usarla.
     */
    public function test_appointments_require_the_scheduling_feature_even_when_services_is_enabled(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['services' => true, 'scheduling' => false]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/appointments')->assertForbidden();
    }

    public function test_appointments_work_when_scheduling_is_enabled_even_if_services_is_disabled(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['services' => false, 'scheduling' => true]]);
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        Appointment::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/appointments')->assertOk();
    }
}
