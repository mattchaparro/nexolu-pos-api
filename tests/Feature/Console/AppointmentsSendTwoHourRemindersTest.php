<?php

namespace Tests\Feature\Console;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppointmentsSendTwoHourRemindersTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.templates.cita_recordatorio' => ['name' => 'appointment_reminder', 'lang' => 'es_CO'],
        ]);
    }

    private function pendingReminder(Appointment $appointment, string $dueDate, string $notifyTime): Reminder
    {
        $user = User::factory()->create(['business_id' => $appointment->business_id]);

        return Reminder::factory()->for($appointment->business, 'business')->create([
            'created_by_user_id' => $user->id,
            'remindable_type' => Appointment::class,
            'remindable_id' => $appointment->id,
            'due_date' => $dueDate,
            'notify_time' => $notifyTime,
        ]);
    }

    public function test_does_not_notify_before_the_notify_time(): void
    {
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-06 08:00', 'America/Bogota'));
        $business = Business::factory()->create();
        $appointment = Appointment::factory()->create(['business_id' => $business->id, 'client_phone' => '3001112233']);
        $this->pendingReminder($appointment, '2026-08-06', '10:00');

        $this->artisan('appointments:send-two-hour-reminders')->assertSuccessful();

        Http::assertNothingSent();
        Carbon::setTestNow();
    }

    public function test_notifies_the_client_phone_after_the_notify_time_and_closes_the_reminder(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:30', 'America/Bogota'));
        $business = Business::factory()->create();
        $appointment = Appointment::factory()->create([
            'business_id' => $business->id,
            'client_name' => 'Ana Gomez',
            'client_phone' => '3001112233',
        ]);
        $reminder = $this->pendingReminder($appointment, '2026-08-06', '10:00');

        $this->artisan('appointments:send-two-hour-reminders')->assertSuccessful();

        Http::assertSent(fn ($request) => $request['to'] === '573001112233'
            && $request['type'] === 'template'
            && $request['template']['name'] === 'appointment_reminder');
        $this->assertSame(Reminder::STATUS_DONE, $reminder->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_does_not_resend_once_done(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:30', 'America/Bogota'));
        $business = Business::factory()->create();
        $appointment = Appointment::factory()->create(['business_id' => $business->id, 'client_phone' => '3001112233']);
        $this->pendingReminder($appointment, '2026-08-06', '10:00');

        $this->artisan('appointments:send-two-hour-reminders')->assertSuccessful();
        $this->artisan('appointments:send-two-hour-reminders')->assertSuccessful();

        Http::assertSentCount(1);
        Carbon::setTestNow();
    }

    public function test_a_cancelled_appointment_is_skipped_and_its_reminder_removed(): void
    {
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:30', 'America/Bogota'));
        $business = Business::factory()->create();
        $appointment = Appointment::factory()->create([
            'business_id' => $business->id,
            'client_phone' => '3001112233',
            'status' => 'cancelled',
        ]);
        $reminder = $this->pendingReminder($appointment, '2026-08-06', '10:00');

        $this->artisan('appointments:send-two-hour-reminders')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertModelMissing($reminder);
        Carbon::setTestNow();
    }

    public function test_does_nothing_without_a_configured_template(): void
    {
        config(['services.whatsapp.templates.cita_recordatorio' => ['name' => null]]);
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:30', 'America/Bogota'));
        $business = Business::factory()->create();
        $appointment = Appointment::factory()->create(['business_id' => $business->id, 'client_phone' => '3001112233']);
        $reminder = $this->pendingReminder($appointment, '2026-08-06', '10:00');

        $this->artisan('appointments:send-two-hour-reminders')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(Reminder::STATUS_PENDING, $reminder->fresh()->status);
        Carbon::setTestNow();
    }
}
