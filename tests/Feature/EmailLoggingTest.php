<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\EmailLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailLoggingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_every_sent_email_is_automatically_logged(): void
    {
        Mail::raw('Cuerpo de prueba', function ($message) {
            $message->to('cliente@example.com')->subject('Recordatorio de pago');
        });

        $this->assertDatabaseHas('email_logs', [
            'to_email' => 'cliente@example.com',
            'subject' => 'Recordatorio de pago',
            'status' => EmailLog::STATUS_SENT,
            'type' => 'generico',
        ]);
    }

    public function test_custom_headers_classify_the_log_by_business_and_type(): void
    {
        $business = Business::factory()->create();

        Mail::raw('Cuerpo', function ($message) use ($business) {
            $message->to('negocio@example.com')
                ->subject('Bienvenido')
                ->getHeaders()
                ->addTextHeader('X-Nexolu-Business-Id', (string) $business->id)
                ->addTextHeader('X-Nexolu-Email-Type', 'welcome');
        });

        $this->assertDatabaseHas('email_logs', [
            'to_email' => 'negocio@example.com',
            'business_id' => $business->id,
            'type' => 'welcome',
        ]);
    }
}
