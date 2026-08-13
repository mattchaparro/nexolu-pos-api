<?php

namespace Tests\Feature\Console;

use App\Mail\LowStockAlertMail;
use App\Models\AiChannelIdentity;
use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InventorySendLowStockAlertsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.templates.inventario_bajo' => ['name' => 'low_stock_alert', 'lang' => 'es_CO'],
        ]);
    }

    private function linkWhatsApp(Business $business, User $admin, string $externalId = '573001234567'): void
    {
        AiChannelIdentity::create([
            'business_id' => $business->id,
            'user_id' => $admin->id,
            'channel' => 'whatsapp',
            'external_id' => $externalId,
            'verified_at' => now(),
        ]);
    }

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

    public function test_includes_low_stock_ingredients_alongside_products(): void
    {
        Mail::fake();
        $business = $this->businessWithAdmin(['low_stock_email' => 'dueno@example.com']);
        Ingredient::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
            'stock' => 1,
            'min_stock' => 5,
        ]);

        $this->artisan('inventory:send-low-stock-alerts')->assertSuccessful();

        Mail::assertSent(LowStockAlertMail::class, fn ($mail) => $mail->hasTo('dueno@example.com')
            && $mail->items->count() === 1
            && $mail->items->first()['kind'] === 'ingredient');
    }

    public function test_sends_a_whatsapp_alert_to_a_linked_admin_who_opted_in(): void
    {
        Mail::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        $business = Business::factory()->create([
            'low_stock_email_enabled' => false,
            'notification_preferences' => ['inventario_bajo' => true],
        ]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');
        $this->linkWhatsApp($business, $admin);
        Product::factory()->create([
            'business_id' => $business->id, 'is_active' => true, 'track_stock' => true,
            'is_single_sale' => false, 'stock' => 1, 'low_stock_alert_threshold' => 5,
        ]);

        $this->artisan('inventory:send-low-stock-alerts')->assertSuccessful();

        Http::assertSent(function ($request) use ($business) {
            $params = $request['template']['components'][0]['parameters'];

            return $request['template']['name'] === 'low_stock_alert'
                && $request['to'] === '573001234567'
                && $params[0]['text'] === $business->name
                && $params[1]['text'] === '1';
        });
    }

    public function test_does_not_send_whatsapp_without_opting_in_even_with_a_linked_number(): void
    {
        Mail::fake();
        Http::fake();
        $business = Business::factory()->create(['low_stock_email_enabled' => false]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');
        $this->linkWhatsApp($business, $admin);
        Product::factory()->create([
            'business_id' => $business->id, 'is_active' => true, 'track_stock' => true,
            'is_single_sale' => false, 'stock' => 1, 'low_stock_alert_threshold' => 5,
        ]);

        $this->artisan('inventory:send-low-stock-alerts')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_sends_both_channels_independently_when_both_are_enabled(): void
    {
        Mail::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        $business = Business::factory()->create([
            'low_stock_email_enabled' => true,
            'low_stock_email' => 'dueno@example.com',
            'notification_preferences' => ['inventario_bajo' => true],
        ]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');
        $this->linkWhatsApp($business, $admin);
        Product::factory()->create([
            'business_id' => $business->id, 'is_active' => true, 'track_stock' => true,
            'is_single_sale' => false, 'stock' => 1, 'low_stock_alert_threshold' => 5,
        ]);

        $this->artisan('inventory:send-low-stock-alerts')->assertSuccessful();

        Mail::assertSent(LowStockAlertMail::class);
        Http::assertSentCount(1);
    }

    public function test_does_not_notify_a_business_with_inventory_enabled_but_low_stock_alert_disabled(): void
    {
        Mail::fake();
        $business = $this->businessWithAdmin([
            'low_stock_email' => 'dueno@example.com',
            'feature_flags' => ['inventory' => true, 'low_stock_alert' => false],
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

    public function test_does_nothing_by_whatsapp_when_the_template_is_not_configured(): void
    {
        config(['services.whatsapp.templates.inventario_bajo' => ['name' => null]]);
        Mail::fake();
        Http::fake();
        $business = Business::factory()->create([
            'low_stock_email_enabled' => false,
            'notification_preferences' => ['inventario_bajo' => true],
        ]);
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');
        $this->linkWhatsApp($business, $admin);
        Product::factory()->create([
            'business_id' => $business->id, 'is_active' => true, 'track_stock' => true,
            'is_single_sale' => false, 'stock' => 1, 'low_stock_alert_threshold' => 5,
        ]);

        $this->artisan('inventory:send-low-stock-alerts')->assertSuccessful();

        Http::assertNothingSent();
    }
}
