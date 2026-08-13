<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Mail\BusinessDirectedMail;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

/**
 * Comunicacion puntual (correo o WhatsApp) que un superadmin redacta y
 * manda a un negocio, con asunto/cuerpo libres - ver BusinessCommunicationService.
 */
class BusinessCommunicationsTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.templates.recordatorio' => ['name' => 'general_reminder', 'lang' => 'es_CO'],
        ]);
    }

    public function test_sends_a_free_form_email_to_the_business_admin(): void
    {
        Mail::fake();

        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id, 'email' => 'owner@example.com']);
        $admin->assignRole('admin');

        $response = $this->actingAs($this->superadmin(), 'sanctum')->postJson(
            "/api/v1/superadmin/businesses/{$business->id}/communications",
            ['channel' => 'email', 'subject' => 'Aviso importante', 'message' => 'Hola, este es un mensaje de prueba.']
        );

        $response->assertOk()->assertJsonPath('ok', true);

        Mail::assertSent(BusinessDirectedMail::class, fn ($mail) => $mail->hasTo('owner@example.com')
            && $mail->emailSubject === 'Aviso importante'
            && $mail->body === 'Hola, este es un mensaje de prueba.');
    }

    public function test_rejects_email_when_the_business_has_no_admin(): void
    {
        Mail::fake();

        $business = Business::factory()->create();

        $response = $this->actingAs($this->superadmin(), 'sanctum')->postJson(
            "/api/v1/superadmin/businesses/{$business->id}/communications",
            ['channel' => 'email', 'subject' => 'Aviso', 'message' => 'Hola']
        );

        $response->assertStatus(422)->assertJsonValidationErrors('channel');
        Mail::assertNothingSent();
    }

    public function test_sends_a_free_form_whatsapp_message_using_the_generic_template(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $business = Business::factory()->create(['whatsapp_number' => '3001112233']);

        $response = $this->actingAs($this->superadmin(), 'sanctum')->postJson(
            "/api/v1/superadmin/businesses/{$business->id}/communications",
            ['channel' => 'whatsapp', 'message' => 'Hola, este es un mensaje de prueba.']
        );

        $response->assertOk()->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => $request['to'] === '573001112233'
            && $request['template']['name'] === 'general_reminder'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Hola, este es un mensaje de prueba.');

        $this->assertDatabaseHas('whatsapp_logs', [
            'business_id' => $business->id,
            'to_phone' => '573001112233',
            'type' => 'superadmin_directed',
        ]);
    }

    public function test_rejects_whatsapp_when_the_business_has_no_valid_number(): void
    {
        $business = Business::factory()->create(['whatsapp_number' => null]);

        $response = $this->actingAs($this->superadmin(), 'sanctum')->postJson(
            "/api/v1/superadmin/businesses/{$business->id}/communications",
            ['channel' => 'whatsapp', 'message' => 'Hola']
        );

        $response->assertStatus(422)->assertJsonValidationErrors('channel');
    }

    public function test_returns_a_gateway_error_when_whatsapp_send_fails(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $business = Business::factory()->create(['whatsapp_number' => '3001112233']);

        $response = $this->actingAs($this->superadmin(), 'sanctum')->postJson(
            "/api/v1/superadmin/businesses/{$business->id}/communications",
            ['channel' => 'whatsapp', 'message' => 'Hola']
        );

        $response->assertStatus(502);
    }

    public function test_rejects_a_whatsapp_message_longer_than_300_characters(): void
    {
        $business = Business::factory()->create(['whatsapp_number' => '3001112233']);

        $response = $this->actingAs($this->superadmin(), 'sanctum')->postJson(
            "/api/v1/superadmin/businesses/{$business->id}/communications",
            ['channel' => 'whatsapp', 'message' => str_repeat('a', 301)]
        );

        $response->assertStatus(422)->assertJsonValidationErrors('message');
    }

    public function test_requires_superadmin(): void
    {
        $business = Business::factory()->create();

        $this->postJson("/api/v1/superadmin/businesses/{$business->id}/communications", [
            'channel' => 'email', 'subject' => 'Aviso', 'message' => 'Hola',
        ])->assertStatus(401);
    }
}
