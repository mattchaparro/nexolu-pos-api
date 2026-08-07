<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class NotificationSnoozeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_valid_signed_link_snoozes_the_business_and_shows_the_confirmation(): void
    {
        $business = Business::factory()->create();
        $url = URL::signedRoute('notifications.low-stock.snooze', ['business' => $business->id, 'days' => 7]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee($business->name);
        $response->assertSee('7 dias');

        $business->refresh();
        $this->assertTrue($business->low_stock_snoozed_until->isFuture());
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $business->low_stock_snoozed_until->timestamp, 5);
    }

    public function test_rejects_a_days_value_outside_the_allowed_options(): void
    {
        $business = Business::factory()->create();
        $url = URL::signedRoute('notifications.low-stock.snooze', ['business' => $business->id, 'days' => 99]);

        $this->get($url)->assertStatus(422);

        $this->assertNull($business->fresh()->low_stock_snoozed_until);
    }

    public function test_rejects_a_tampered_url(): void
    {
        $business = Business::factory()->create();
        $url = URL::signedRoute('notifications.low-stock.snooze', ['business' => $business->id, 'days' => 7]);
        $tampered = str_replace('days=7', 'days=30', $url);

        $this->get($tampered)->assertStatus(403);

        $this->assertNull($business->fresh()->low_stock_snoozed_until);
    }

    public function test_rejects_a_request_without_a_signature(): void
    {
        $business = Business::factory()->create();

        $this->get("/api/notifications/low-stock/{$business->id}/snooze?days=7")->assertStatus(403);
    }
}
