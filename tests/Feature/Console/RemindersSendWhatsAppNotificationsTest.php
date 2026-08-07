<?php

namespace Tests\Feature\Console;

use App\Models\AiChannelIdentity;
use App\Models\Business;
use App\Models\Reminder;
use App\Models\User;
use App\Services\ReminderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemindersSendWhatsAppNotificationsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.templates.recordatorio' => ['name' => 'general_reminder', 'lang' => 'es_CO'],
        ]);
    }

    private function linkedUser(): User
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        AiChannelIdentity::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'external_id' => '573001234567',
            'verified_at' => now(),
        ]);

        return $user;
    }

    public function test_does_not_notify_before_the_notify_time(): void
    {
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-06 08:00'));
        $user = $this->linkedUser();

        Reminder::factory()->for($user->business, 'business')->create([
            'created_by_user_id' => $user->id,
            'due_date' => '2026-08-06',
            'notify_time' => '09:00',
            'notify_whatsapp' => true,
        ]);

        $this->artisan('reminders:send-whatsapp-notifications')->assertSuccessful();

        Http::assertNothingSent();
        Carbon::setTestNow();
    }

    public function test_notifies_after_the_notify_time(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        Carbon::setTestNow(Carbon::parse('2026-08-06 09:30'));
        $user = $this->linkedUser();

        $reminder = Reminder::factory()->for($user->business, 'business')->create([
            'created_by_user_id' => $user->id,
            'title' => 'Pagar arriendo',
            'due_date' => '2026-08-06',
            'notify_time' => '09:00',
            'notify_whatsapp' => true,
        ]);

        $this->artisan('reminders:send-whatsapp-notifications')->assertSuccessful();

        Http::assertSent(fn ($request) => $request['type'] === 'template'
            && $request['template']['name'] === 'general_reminder'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Pagar arriendo');
        $this->assertSame('2026-08-06', $reminder->fresh()->last_notified_on->toDateString());
        Carbon::setTestNow();
    }

    public function test_does_not_resend_the_same_day(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        Carbon::setTestNow(Carbon::parse('2026-08-06 09:30'));
        $user = $this->linkedUser();

        Reminder::factory()->for($user->business, 'business')->create([
            'created_by_user_id' => $user->id,
            'due_date' => '2026-08-06',
            'notify_time' => '09:00',
            'notify_whatsapp' => true,
        ]);

        $this->artisan('reminders:send-whatsapp-notifications')->assertSuccessful();
        $this->artisan('reminders:send-whatsapp-notifications')->assertSuccessful();

        Http::assertSentCount(1);
        Carbon::setTestNow();
    }

    public function test_does_not_notify_without_a_linked_whatsapp_number(): void
    {
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-06 09:30'));
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        Reminder::factory()->for($business, 'business')->create([
            'created_by_user_id' => $user->id,
            'due_date' => '2026-08-06',
            'notify_time' => '09:00',
            'notify_whatsapp' => true,
        ]);

        $this->artisan('reminders:send-whatsapp-notifications')->assertSuccessful();

        Http::assertNothingSent();
        Carbon::setTestNow();
    }

    public function test_does_nothing_without_a_configured_template(): void
    {
        config(['services.whatsapp.templates.recordatorio' => ['name' => null]]);
        Http::fake();
        $user = $this->linkedUser();

        Reminder::factory()->for($user->business, 'business')->create([
            'created_by_user_id' => $user->id,
            'due_date' => now()->toDateString(),
            'notify_time' => '00:00',
            'notify_whatsapp' => true,
        ]);

        $this->artisan('reminders:send-whatsapp-notifications')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_does_not_notify_when_notify_whatsapp_is_false(): void
    {
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-06 09:30'));
        $user = $this->linkedUser();

        Reminder::factory()->for($user->business, 'business')->create([
            'created_by_user_id' => $user->id,
            'due_date' => '2026-08-06',
            'notify_time' => '09:00',
            'notify_whatsapp' => false,
        ]);

        $this->artisan('reminders:send-whatsapp-notifications')->assertSuccessful();

        Http::assertNothingSent();
        Carbon::setTestNow();
    }

    public function test_a_recurring_reminder_notifies_again_on_the_next_cycle(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        Carbon::setTestNow(Carbon::parse('2026-08-06 09:30'));
        $user = $this->linkedUser();

        $reminder = Reminder::factory()->recurring('weekly')->for($user->business, 'business')->create([
            'created_by_user_id' => $user->id,
            'due_date' => '2026-08-06',
            'series_anchor_date' => '2026-08-06',
            'notify_time' => '09:00',
            'notify_whatsapp' => true,
        ]);

        $this->artisan('reminders:send-whatsapp-notifications')->assertSuccessful();
        Http::assertSentCount(1);

        app(ReminderService::class)->complete($reminder);

        Carbon::setTestNow(Carbon::parse('2026-08-13 09:30'));
        $this->artisan('reminders:send-whatsapp-notifications')->assertSuccessful();

        Http::assertSentCount(2);
        Carbon::setTestNow();
    }
}
