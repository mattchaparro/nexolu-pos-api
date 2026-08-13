<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\Business;
use App\Models\EmailLog;
use App\Models\WhatsappLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class CommunicationsTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_lists_email_and_whatsapp_logs_together_ordered_by_recent(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();

        $older = EmailLog::factory()->for($business)->create(['created_at' => now()->subHour()]);
        $newer = WhatsappLog::factory()->for($business)->create(['created_at' => now()]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/communications');

        $response->assertOk()->assertJsonCount(2, 'data');
        $channels = collect($response->json('data'))->pluck('channel');
        $this->assertSame(['whatsapp', 'email'], $channels->all());
        $this->assertSame("whatsapp-{$newer->id}", $response->json('data.0.uid'));
        $this->assertSame("email-{$older->id}", $response->json('data.1.uid'));
    }

    public function test_can_filter_by_channel(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        EmailLog::factory()->for($business)->create();
        WhatsappLog::factory()->for($business)->create();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/communications?channel=whatsapp');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.channel', 'whatsapp');
    }

    public function test_can_filter_by_business(): void
    {
        $admin = $this->superadmin();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        EmailLog::factory()->for($businessA)->create();
        EmailLog::factory()->for($businessB)->create();

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/superadmin/communications?business_id={$businessA->id}");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.business_id', $businessA->id);
    }

    public function test_includes_the_business_name(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create(['name' => 'Peluqueria Ana']);
        EmailLog::factory()->for($business)->create();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/communications');

        $response->assertOk()->assertJsonPath('data.0.business_name', 'Peluqueria Ana');
    }
}
