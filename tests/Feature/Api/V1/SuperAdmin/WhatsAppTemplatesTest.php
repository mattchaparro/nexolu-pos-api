<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class WhatsAppTemplatesTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_lists_configured_and_pending_templates(): void
    {
        config([
            'services.whatsapp.templates.otp' => ['name' => 'verify_whatsapp_in_business', 'lang' => 'es'],
            'services.whatsapp.templates.cita_confirmacion' => ['name' => '', 'lang' => 'es_CO', 'description' => 'Confirmación de cita.'],
        ]);

        $response = $this->actingAs($this->superadmin(), 'sanctum')->getJson('/api/v1/superadmin/whatsapp-templates');

        $response->assertOk();

        $otp = collect($response->json('templates'))->firstWhere('key', 'otp');
        $this->assertSame('verify_whatsapp_in_business', $otp['name']);
        $this->assertSame('configured', $otp['status']);

        $cita = collect($response->json('templates'))->firstWhere('key', 'cita_confirmacion');
        $this->assertNull($cita['name']);
        $this->assertSame('pending', $cita['status']);
        $this->assertSame('Confirmación de cita.', $cita['description']);
    }

    public function test_lists_configured_and_pending_flows(): void
    {
        config([
            'services.whatsapp.flows.gasto' => ['id' => '', 'screen' => 'GASTO', 'description' => 'Confirmar gasto.'],
        ]);

        $response = $this->actingAs($this->superadmin(), 'sanctum')->getJson('/api/v1/superadmin/whatsapp-templates');

        $response->assertOk();

        $flow = collect($response->json('flows'))->firstWhere('key', 'gasto');
        $this->assertNull($flow['id']);
        $this->assertSame('pending', $flow['status']);
        $this->assertSame('GASTO', $flow['screen']);
    }

    public function test_requires_superadmin(): void
    {
        $this->getJson('/api/v1/superadmin/whatsapp-templates')->assertStatus(401);
    }
}
