<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SendAppointmentConfirmationJob;
use App\Models\Appointment;
use App\Models\Business;
use App\Services\AppointmentWhatsappNotifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendAppointmentConfirmationJobTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.templates.cita_confirmacion' => ['name' => 'appointment_confirmation', 'lang' => 'es_CO'],
        ]);
    }

    public function test_sends_the_confirmation_template_to_the_client_phone(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        $business = Business::factory()->create(['name' => 'Peluquería Ana']);
        $appointment = Appointment::factory()->create([
            'business_id' => $business->id,
            'client_name' => 'Carla Ruiz',
            'client_phone' => '3001112233',
        ]);

        (new SendAppointmentConfirmationJob($appointment->id))->handle(app(AppointmentWhatsappNotifier::class));

        Http::assertSent(fn ($request) => $request['to'] === '573001112233'
            && $request['type'] === 'template'
            && $request['template']['name'] === 'appointment_confirmation');
    }

    public function test_does_nothing_for_a_missing_appointment(): void
    {
        Http::fake();

        (new SendAppointmentConfirmationJob(999999))->handle(app(AppointmentWhatsappNotifier::class));

        Http::assertNothingSent();
    }

    public function test_does_nothing_without_a_configured_template(): void
    {
        config(['services.whatsapp.templates.cita_confirmacion' => ['name' => null]]);
        Http::fake();
        $business = Business::factory()->create();
        $appointment = Appointment::factory()->create(['business_id' => $business->id, 'client_phone' => '3001112233']);

        (new SendAppointmentConfirmationJob($appointment->id))->handle(app(AppointmentWhatsappNotifier::class));

        Http::assertNothingSent();
    }
}
