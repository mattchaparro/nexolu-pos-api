<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\Business;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class EmailsTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_superadmin_can_list_and_filter_email_logs(): void
    {
        $admin = $this->superadmin();
        $business = Business::factory()->create();
        EmailLog::factory()->create(['business_id' => $business->id, 'status' => 'sent']);
        EmailLog::factory()->create(['business_id' => $business->id, 'status' => 'failed']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/emails/logs')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/emails/logs?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_superadmin_can_update_an_email_template(): void
    {
        $admin = $this->superadmin();
        EmailTemplate::factory()->create(['type' => 'trial_winback', 'subject' => 'Old subject']);

        $response = $this->actingAs($admin, 'sanctum')->putJson('/api/v1/superadmin/emails/templates/trial_winback', [
            'subject' => 'Nuevo asunto',
            'fields' => ['cta' => 'Reactivar'],
        ]);

        $response->assertOk()->assertJsonPath('subject', 'Nuevo asunto');
        $this->assertDatabaseHas('email_templates', ['type' => 'trial_winback', 'subject' => 'Nuevo asunto']);
    }

    public function test_updating_a_template_that_does_not_exist_creates_it(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/superadmin/emails/templates/brand_new_type', [
            'subject' => 'Asunto',
        ])->assertSuccessful();

        $this->assertDatabaseHas('email_templates', ['type' => 'brand_new_type']);
    }
}
