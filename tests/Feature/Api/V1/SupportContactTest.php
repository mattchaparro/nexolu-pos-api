<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use App\Support\SystemConfigStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Soporte por WhatsApp: reemplaza al modulo de tickets, que nadie uso nunca.
 */
class SupportContactTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_builds_a_whatsapp_link_that_identifies_who_writes(): void
    {
        config(['support.whatsapp_number' => '573239251072']);

        $business = Business::factory()->create(['name' => 'Panaderia Lucia']);
        $user = User::factory()->create(['business_id' => $business->id, 'name' => 'Marta Lopez']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/support/contact')->assertOk();

        $this->assertSame('573239251072', $response->json('whatsapp_number'));
        // Sin identificarse, soporte recibe un "hola" sin saber quien escribe.
        $this->assertStringContainsString(rawurlencode('Marta Lopez'), $response->json('whatsapp_url'));
        $this->assertStringContainsString(rawurlencode('Panaderia Lucia'), $response->json('whatsapp_url'));
    }

    /** Cambiar el numero no puede exigir un despliegue. */
    public function test_system_config_overrides_the_configured_number(): void
    {
        config(['support.whatsapp_number' => '573239251072']);
        SystemConfigStore::putMany(['billing.whatsapp_number' => '573001112233']);

        $user = User::factory()->create(['business_id' => Business::factory()->create()->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/support/contact')
            ->assertOk()
            ->assertJsonPath('whatsapp_number', '573001112233');
    }

    /**
     * Un dedazo guardando el numero no puede dejar al producto sin canal de
     * soporte: se cae al de config en vez de devolver null.
     */
    public function test_an_invalid_stored_number_falls_back_to_the_configured_one(): void
    {
        config(['support.whatsapp_number' => '573239251072']);
        SystemConfigStore::putMany(['billing.whatsapp_number' => 'no-es-un-numero']);

        $user = User::factory()->create(['business_id' => Business::factory()->create()->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/support/contact')
            ->assertOk()
            ->assertJsonPath('whatsapp_number', '573239251072');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/support/contact')->assertUnauthorized();
    }
}
