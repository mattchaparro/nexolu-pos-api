<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\SendAnnouncementWhatsApp;
use App\Models\Announcement;
use App\Models\Business;
use App\Models\User;
use App\Services\Messaging\Contracts\MessagingChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

/**
 * Difusiones del superadmin: el aviso que el admin ve al entrar al POS y el
 * WhatsApp opcional a cada negocio.
 */
class AnnouncementBroadcastTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['subscriptions.lockout_not_before' => null]);
    }

    private function adminOf(Business $business): User
    {
        $user = User::factory()->create(['business_id' => $business->id, 'created_at' => now()->subMonth()]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_an_admin_sees_the_broadcast_once_and_not_after_closing_it(): void
    {
        $business = Business::factory()->create(['active' => true, 'trial_ends_at' => now()->addDays(10)]);
        $admin = $this->adminOf($business);
        $announcement = Announcement::factory()->create(['title' => 'El asistente ya responde márgenes', 'audience' => 'admin', 'business_scope' => 'in_use']);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/announcements/pending')
            ->assertOk()
            ->assertJsonPath('data.title', 'El asistente ya responde márgenes');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/announcements/{$announcement->id}/dismiss")->assertNoContent();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/announcements/pending')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_it_reaches_only_the_role_and_businesses_it_was_sent_to(): void
    {
        $inUse = Business::factory()->create(['active' => true, 'trial_ends_at' => now()->addDays(10)]);
        $lockedOut = Business::factory()->lockedOut()->create(['active' => true]);
        Announcement::factory()->create(['audience' => 'admin', 'business_scope' => 'in_use']);

        $employee = User::factory()->create(['business_id' => $inUse->id, 'created_at' => now()->subMonth()]);
        $employee->assignRole('employee');

        $this->actingAs($employee, 'sanctum')->getJson('/api/v1/announcements/pending')->assertJsonPath('data', null);
        $this->actingAs($this->adminOf($lockedOut), 'sanctum')->getJson('/api/v1/announcements/pending')->assertJsonPath('data', null);
        $this->actingAs($this->adminOf($inUse), 'sanctum')->getJson('/api/v1/announcements/pending')->assertJsonPath('data.id', fn ($id) => $id !== null);
    }

    public function test_a_selected_broadcast_only_reaches_the_chosen_businesses(): void
    {
        $chosen = Business::factory()->create(['active' => true]);
        $other = Business::factory()->create(['active' => true]);
        Announcement::factory()->create(['audience' => 'all', 'business_scope' => 'selected', 'business_ids' => [$chosen->id]]);

        $this->actingAs($this->adminOf($other), 'sanctum')->getJson('/api/v1/announcements/pending')->assertJsonPath('data', null);
        $this->actingAs($this->adminOf($chosen), 'sanctum')->getJson('/api/v1/announcements/pending')->assertJsonPath('data.id', fn ($id) => $id !== null);
    }

    public function test_the_superadmin_sees_how_many_businesses_it_reaches_before_sending(): void
    {
        Business::factory()->create(['active' => true, 'trial_ends_at' => now()->addDays(10), 'whatsapp_number' => '3001234567']);
        Business::factory()->create(['active' => true, 'trial_ends_at' => now()->addDays(10), 'whatsapp_number' => null]);
        Business::factory()->lockedOut()->create(['active' => true]);

        $this->actingAs($this->superadmin(), 'sanctum')
            ->getJson('/api/v1/superadmin/announcements/audience?business_scope=in_use')
            ->assertOk()
            ->assertJsonPath('businesses', fn ($n) => $n >= 2)
            ->assertJsonPath('with_whatsapp', fn ($n) => $n >= 1);
    }

    public function test_sending_by_whatsapp_queues_the_template_for_each_business(): void
    {
        config(['services.whatsapp.templates.novedades.name' => 'pos_novedades']);
        Bus::fake();

        $this->actingAs($this->superadmin(), 'sanctum')->postJson('/api/v1/superadmin/announcements', [
            'title' => 'El asistente está potenciado',
            'body' => 'Pregúntale por márgenes y reposición.',
            'audience' => 'admin',
            'business_scope' => 'in_use',
            'send_whatsapp' => true,
        ])->assertCreated()->assertJsonPath('whatsapp_requested', true);

        Bus::assertDispatched(SendAnnouncementWhatsApp::class);
    }

    public function test_the_whatsapp_job_sends_the_template_with_the_business_and_the_text(): void
    {
        config(['services.whatsapp.templates.novedades.name' => 'pos_novedades']);
        $business = Business::factory()->create(['name' => 'Central Cell', 'active' => true, 'whatsapp_number' => '3001234567']);
        $announcement = Announcement::factory()->create([
            'title' => 'Novedad',
            'body' => "Línea uno\nLínea dos",
            'business_scope' => 'selected',
            'business_ids' => [$business->id],
        ]);

        $this->mock(MessagingChannel::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendTemplate')
                ->once()
                ->withArgs(fn (string $to, string $name, string $lang, array $components) => $to === '573001234567'
                    && $name === 'pos_novedades'
                    && $components[0]['parameters'][0]['text'] === 'Central Cell'
                    // Meta rechaza saltos de linea dentro de una variable.
                    && ! str_contains($components[0]['parameters'][1]['text'], "\n"))
                ->andReturnTrue();
        });

        (new SendAnnouncementWhatsApp($announcement->id))->handle(app(MessagingChannel::class));

        $this->assertSame(1, $announcement->fresh()->whatsapp_sent);
    }

    public function test_whatsapp_cannot_be_requested_without_the_template(): void
    {
        config(['services.whatsapp.templates.novedades.name' => '']);

        $this->actingAs($this->superadmin(), 'sanctum')->postJson('/api/v1/superadmin/announcements', [
            'title' => 'Novedad',
            'body' => 'Texto',
            'audience' => 'admin',
            'send_whatsapp' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('send_whatsapp');
    }
}
