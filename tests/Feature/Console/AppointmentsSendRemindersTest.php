<?php

namespace Tests\Feature\Console;

use App\Mail\AppointmentReminderMail;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Client;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AppointmentsSendRemindersTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sends_a_reminder_for_an_appointment_tomorrow(): void
    {
        Mail::fake();
        $business = Business::factory()->create();
        $appointment = Appointment::factory()->create([
            'business_id' => $business->id,
            'client_email' => 'cliente@example.com',
            'starts_at' => Carbon::tomorrow('America/Bogota')->setTime(10, 0),
            'ends_at' => Carbon::tomorrow('America/Bogota')->setTime(11, 0),
            'status' => 'confirmed',
            'reminder_sent' => false,
        ]);

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Mail::assertSent(AppointmentReminderMail::class, fn ($mail) => $mail->hasTo('cliente@example.com')
            && $mail->appointment->is($appointment));
        $this->assertTrue($appointment->fresh()->reminder_sent);
    }

    public function test_does_not_notify_an_appointment_outside_tomorrows_window(): void
    {
        Mail::fake();
        Appointment::factory()->create([
            'client_email' => 'cliente@example.com',
            'starts_at' => Carbon::tomorrow('America/Bogota')->addDays(3)->setTime(10, 0),
            'ends_at' => Carbon::tomorrow('America/Bogota')->addDays(3)->setTime(11, 0),
            'status' => 'confirmed',
            'reminder_sent' => false,
        ]);

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_notify_a_cancelled_appointment(): void
    {
        Mail::fake();
        Appointment::factory()->create([
            'client_email' => 'cliente@example.com',
            'starts_at' => Carbon::tomorrow('America/Bogota')->setTime(10, 0),
            'ends_at' => Carbon::tomorrow('America/Bogota')->setTime(11, 0),
            'status' => 'cancelled',
            'reminder_sent' => false,
        ]);

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_resend_an_already_reminded_appointment(): void
    {
        Mail::fake();
        Appointment::factory()->create([
            'client_email' => 'cliente@example.com',
            'starts_at' => Carbon::tomorrow('America/Bogota')->setTime(10, 0),
            'ends_at' => Carbon::tomorrow('America/Bogota')->setTime(11, 0),
            'status' => 'confirmed',
            'reminder_sent' => true,
        ]);

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_marks_reminder_sent_without_emailing_when_no_email_is_available(): void
    {
        Mail::fake();
        $appointment = Appointment::factory()->create([
            'client_email' => null,
            'starts_at' => Carbon::tomorrow('America/Bogota')->setTime(10, 0),
            'ends_at' => Carbon::tomorrow('America/Bogota')->setTime(11, 0),
            'status' => 'confirmed',
            'reminder_sent' => false,
        ]);

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertTrue($appointment->fresh()->reminder_sent);
    }

    public function test_falls_back_to_the_linked_clients_email(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['email' => 'del-cliente@example.com']);
        Appointment::factory()->create([
            'client_id' => $client->id,
            'client_email' => null,
            'starts_at' => Carbon::tomorrow('America/Bogota')->setTime(10, 0),
            'ends_at' => Carbon::tomorrow('America/Bogota')->setTime(11, 0),
            'status' => 'confirmed',
            'reminder_sent' => false,
        ]);

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Mail::assertSent(AppointmentReminderMail::class, fn ($mail) => $mail->hasTo('del-cliente@example.com'));
    }
}
