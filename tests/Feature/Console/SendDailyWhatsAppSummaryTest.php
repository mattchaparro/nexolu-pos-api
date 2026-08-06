<?php

namespace Tests\Feature\Console;

use App\Models\AiChannelIdentity;
use App\Models\Business;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendDailyWhatsAppSummaryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.templates.resumen_diario' => ['name' => 'daily_business_summary', 'lang' => 'es_CO'],
        ]);
    }

    private function businessWithLinkedAdmin(array $businessAttributes = [], string $externalId = '573001234567'): Business
    {
        $business = Business::factory()->create(array_merge([
            'notification_preferences' => ['resumen_diario' => true],
        ], $businessAttributes));

        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        AiChannelIdentity::create([
            'business_id' => $business->id,
            'user_id' => $admin->id,
            'channel' => 'whatsapp',
            'external_id' => $externalId,
            'verified_at' => now(),
        ]);

        return $business;
    }

    public function test_does_nothing_without_a_configured_template(): void
    {
        config(['services.whatsapp.templates.resumen_diario' => ['name' => null]]);
        Http::fake();
        $business = $this->businessWithLinkedAdmin();
        Sale::factory()->create(['business_id' => $business->id, 'total' => 100000, 'closed_at' => now()]);

        $this->artisan('notifications:send-daily-whatsapp-summary')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_does_not_notify_a_business_that_has_not_opted_in(): void
    {
        Http::fake();
        $business = $this->businessWithLinkedAdmin(['notification_preferences' => ['resumen_diario' => false]]);
        Sale::factory()->create(['business_id' => $business->id, 'total' => 100000, 'closed_at' => now()]);

        $this->artisan('notifications:send-daily-whatsapp-summary')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_does_not_notify_without_a_linked_admin(): void
    {
        Http::fake();
        $business = Business::factory()->create(['notification_preferences' => ['resumen_diario' => true]]);
        Sale::factory()->create(['business_id' => $business->id, 'total' => 100000, 'closed_at' => now()]);

        $this->artisan('notifications:send-daily-whatsapp-summary')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_does_not_notify_when_there_is_nothing_worth_reporting(): void
    {
        Http::fake();
        $this->businessWithLinkedAdmin();

        $this->artisan('notifications:send-daily-whatsapp-summary')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_sends_the_summary_template_with_the_expected_components(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        $business = $this->businessWithLinkedAdmin();
        Sale::factory()->create(['business_id' => $business->id, 'total' => 100000, 'closed_at' => now()]);

        $this->artisan('notifications:send-daily-whatsapp-summary')->assertSuccessful();

        Http::assertSent(function ($request) {
            $params = $request['template']['components'][0]['parameters'];

            return $request['template']['name'] === 'daily_business_summary'
                && $request['to'] === '573001234567'
                && count($params) === 4
                && str_contains($params[1]['text'], 'Ventas: $100.000');
        });
    }

    public function test_respects_the_business_id_option(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        $target = $this->businessWithLinkedAdmin();
        Sale::factory()->create(['business_id' => $target->id, 'total' => 100000, 'closed_at' => now()]);

        $other = $this->businessWithLinkedAdmin(externalId: '573009876543');
        Sale::factory()->create(['business_id' => $other->id, 'total' => 100000, 'closed_at' => now()]);

        $this->artisan('notifications:send-daily-whatsapp-summary', ['--business_id' => $target->id])->assertSuccessful();

        Http::assertSentCount(1);
    }
}
