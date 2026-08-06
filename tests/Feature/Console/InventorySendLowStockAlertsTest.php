<?php

namespace Tests\Feature\Console;

use App\Mail\LowStockAlertMail;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InventorySendLowStockAlertsTest extends TestCase
{
    use DatabaseTransactions;

    private function businessWithAdmin(array $businessAttributes = []): Business
    {
        $business = Business::factory()->create(array_merge([
            'low_stock_email_enabled' => true,
        ], $businessAttributes));

        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return $business;
    }

    public function test_sends_an_alert_when_a_product_is_below_its_threshold(): void
    {
        Mail::fake();
        $business = $this->businessWithAdmin(['low_stock_email' => 'dueno@example.com']);
        Product::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
            'track_stock' => true,
            'is_single_sale' => false,
            'stock' => 2,
            'low_stock_alert_threshold' => 5,
        ]);

        $this->artisan('inventory:send-low-stock-alerts')->assertSuccessful();

        Mail::assertSent(LowStockAlertMail::class, fn ($mail) => $mail->hasTo('dueno@example.com')
            && $mail->items->count() === 1);
    }

    public function test_falls_back_to_the_admin_email_when_no_low_stock_email_is_set(): void
    {
        Mail::fake();
        $business = $this->businessWithAdmin(['low_stock_email' => null]);
        $admin = $business->users()->first();
        Product::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
            'track_stock' => true,
            'is_single_sale' => false,
            'stock' => 1,
            'low_stock_alert_threshold' => 5,
        ]);

        $this->artisan('inventory:send-low-stock-alerts')->assertSuccessful();

        Mail::assertSent(LowStockAlertMail::class, fn ($mail) => $mail->hasTo($admin->email));
    }

    public function test_does_not_notify_when_stock_is_above_the_threshold(): void
    {
        Mail::fake();
        $business = $this->businessWithAdmin(['low_stock_email' => 'dueno@example.com']);
        Product::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
            'track_stock' => true,
            'is_single_sale' => false,
            'stock' => 50,
            'low_stock_alert_threshold' => 5,
        ]);

        $this->artisan('inventory:send-low-stock-alerts')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_notify_a_business_with_the_email_channel_disabled(): void
    {
        Mail::fake();
        $business = $this->businessWithAdmin([
            'low_stock_email_enabled' => false,
            'low_stock_email' => 'dueno@example.com',
        ]);
        Product::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
            'track_stock' => true,
            'is_single_sale' => false,
            'stock' => 1,
            'low_stock_alert_threshold' => 5,
        ]);

        $this->artisan('inventory:send-low-stock-alerts')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_notify_a_snoozed_business(): void
    {
        Mail::fake();
        $business = $this->businessWithAdmin([
            'low_stock_email' => 'dueno@example.com',
            'low_stock_snoozed_until' => now()->addDay(),
        ]);
        Product::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
            'track_stock' => true,
            'is_single_sale' => false,
            'stock' => 1,
            'low_stock_alert_threshold' => 5,
        ]);

        $this->artisan('inventory:send-low-stock-alerts')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_ignores_products_that_do_not_track_stock(): void
    {
        Mail::fake();
        $business = $this->businessWithAdmin(['low_stock_email' => 'dueno@example.com']);
        Product::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
            'track_stock' => false,
            'is_single_sale' => false,
            'stock' => 0,
            'low_stock_alert_threshold' => 5,
        ]);

        $this->artisan('inventory:send-low-stock-alerts')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_respects_the_business_id_option(): void
    {
        Mail::fake();
        $target = $this->businessWithAdmin(['low_stock_email' => 'target@example.com']);
        Product::factory()->create([
            'business_id' => $target->id,
            'is_active' => true,
            'track_stock' => true,
            'is_single_sale' => false,
            'stock' => 1,
            'low_stock_alert_threshold' => 5,
        ]);

        $other = $this->businessWithAdmin(['low_stock_email' => 'other@example.com']);
        Product::factory()->create([
            'business_id' => $other->id,
            'is_active' => true,
            'track_stock' => true,
            'is_single_sale' => false,
            'stock' => 1,
            'low_stock_alert_threshold' => 5,
        ]);

        $this->artisan('inventory:send-low-stock-alerts', ['--business_id' => $target->id])->assertSuccessful();

        Mail::assertSentCount(1);
        Mail::assertSent(LowStockAlertMail::class, fn ($mail) => $mail->hasTo('target@example.com'));
    }
}
