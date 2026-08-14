<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiChannelIdentity;
use App\Models\AiChannelLinkChallenge;
use App\Models\Business;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiChannelLinkTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::sync();
        // Sin credenciales de WhatsApp: el OTP cae al LogChannelOtpSender, no
        // intenta un envio real via Graph API en estos tests.
        config(['services.whatsapp.access_token' => null]);
    }

    private function adminUser(): User
    {
        $business = Business::factory()->create();
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_rejects_a_user_without_the_ai_chat_permission(): void
    {
        $business = Business::factory()->create();
        $employee = User::factory()->create(['business_id' => $business->id]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/v1/ai/channels/whatsapp/start', ['phone' => '3001234567'])
            ->assertStatus(403);
    }

    public function test_starting_a_link_creates_a_challenge(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/channels/whatsapp/start', ['phone' => '3001234567'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('ai_channel_link_challenges', [
            'user_id' => $admin->id,
            'channel' => 'whatsapp',
            'external_id' => '573001234567',
        ]);
    }

    public function test_starting_a_link_rejects_an_invalid_phone(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/channels/whatsapp/start', ['phone' => '123'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_starting_a_link_rejects_a_number_already_verified_for_another_user(): void
    {
        $admin = $this->adminUser();
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        AiChannelIdentity::withoutGlobalScopes()->create([
            'business_id' => $otherBusiness->id,
            'user_id' => $otherUser->id,
            'channel' => 'whatsapp',
            'external_id' => '573001234567',
            'verified_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/channels/whatsapp/start', ['phone' => '3001234567'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_confirming_the_correct_code_links_the_number(): void
    {
        $admin = $this->adminUser();

        $challenge = AiChannelLinkChallenge::create([
            'business_id' => $admin->business_id,
            'user_id' => $admin->id,
            'channel' => 'whatsapp',
            'external_id' => '573001234567',
            'code_hash' => bcrypt('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/channels/whatsapp/confirm', ['code' => '123456'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('ai_channel_identities', [
            'user_id' => $admin->id,
            'channel' => 'whatsapp',
            'external_id' => '573001234567',
        ]);
        $this->assertNotNull($challenge->fresh()->consumed_at);
    }

    public function test_confirming_a_wrong_code_increments_attempts_and_fails(): void
    {
        $admin = $this->adminUser();

        $challenge = AiChannelLinkChallenge::create([
            'business_id' => $admin->business_id,
            'user_id' => $admin->id,
            'channel' => 'whatsapp',
            'external_id' => '573001234567',
            'code_hash' => bcrypt('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/channels/whatsapp/confirm', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertSame(1, $challenge->fresh()->attempts);
        $this->assertDatabaseMissing('ai_channel_identities', ['user_id' => $admin->id]);
    }

    public function test_confirming_an_expired_challenge_fails(): void
    {
        $admin = $this->adminUser();

        AiChannelLinkChallenge::create([
            'business_id' => $admin->business_id,
            'user_id' => $admin->id,
            'channel' => 'whatsapp',
            'external_id' => '573001234567',
            'code_hash' => bcrypt('123456'),
            'expires_at' => now()->subMinute(),
            'attempts' => 0,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/channels/whatsapp/confirm', ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_confirming_sends_the_welcome_template_when_whatsapp_is_configured(): void
    {
        config([
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1234567890',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $admin = $this->adminUser();
        AiChannelLinkChallenge::create([
            'business_id' => $admin->business_id,
            'user_id' => $admin->id,
            'channel' => 'whatsapp',
            'external_id' => '573001234567',
            'code_hash' => bcrypt('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ai/channels/whatsapp/confirm', ['code' => '123456'])
            ->assertOk();

        Http::assertSent(fn ($request) => $request['type'] === 'template'
            && $request['template']['name'] === 'welcome_whatsapp_linked');
    }

    public function test_status_reports_not_linked_when_there_is_no_identity(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/ai/channels/whatsapp/status')
            ->assertOk()
            ->assertJsonPath('linked', false);
    }

    public function test_status_reports_linked_when_an_identity_exists(): void
    {
        $admin = $this->adminUser();
        AiChannelIdentity::create([
            'business_id' => $admin->business_id,
            'user_id' => $admin->id,
            'channel' => 'whatsapp',
            'external_id' => '573001234567',
            'verified_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/ai/channels/whatsapp/status')
            ->assertOk()
            ->assertJsonPath('linked', true);
    }

    public function test_unlink_removes_the_identity(): void
    {
        $admin = $this->adminUser();
        AiChannelIdentity::create([
            'business_id' => $admin->business_id,
            'user_id' => $admin->id,
            'channel' => 'whatsapp',
            'external_id' => '573001234567',
            'verified_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/ai/channels/whatsapp')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('ai_channel_identities', ['user_id' => $admin->id]);
    }
}
