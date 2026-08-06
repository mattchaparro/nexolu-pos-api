<?php

namespace Tests\Feature\Console;

use App\Mail\SubscriptionExpiringMail;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubscriptionsNotifyExpiringTest extends TestCase
{
    use DatabaseTransactions;

    private function ownerFor(Business $business): User
    {
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $owner->assignRole('admin');

        return $owner;
    }

    public function test_notifies_a_business_whose_trial_expires_within_the_window(): void
    {
        Mail::fake();
        $business = Business::factory()->create(['trial_ends_at' => now()->addDays(2), 'paid_until' => null]);
        $owner = $this->ownerFor($business);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Mail::assertSent(SubscriptionExpiringMail::class, fn ($mail) => $mail->hasTo($owner->email) && ! $mail->isPaidSubscription);
        $this->assertNotNull($business->fresh()->subscription_expiry_notified_at);
    }

    public function test_notifies_a_business_whose_paid_subscription_expires_within_the_window(): void
    {
        Mail::fake();
        $business = Business::factory()->create(['trial_ends_at' => null, 'paid_until' => now()->addDays(1)]);
        $owner = $this->ownerFor($business);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Mail::assertSent(SubscriptionExpiringMail::class, fn ($mail) => $mail->hasTo($owner->email) && $mail->isPaidSubscription);
    }

    public function test_does_not_notify_a_business_outside_the_window(): void
    {
        Mail::fake();
        Business::factory()->create(['trial_ends_at' => now()->addDays(10), 'paid_until' => null]);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_notify_an_inactive_business(): void
    {
        Mail::fake();
        Business::factory()->create(['active' => false, 'trial_ends_at' => now()->addDays(1), 'paid_until' => null]);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_renotify_within_the_same_expiry_window(): void
    {
        Mail::fake();
        $business = Business::factory()->create(['trial_ends_at' => now()->addDays(2), 'paid_until' => null]);
        $this->ownerFor($business);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();
        Mail::assertSentCount(1);

        // Correr de nuevo el mismo dia (o el siguiente, todavia dentro de la
        // misma ventana de aviso) no debe volver a mandar el correo.
        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    public function test_notifies_again_after_the_business_renews(): void
    {
        Mail::fake();
        $business = Business::factory()->create(['trial_ends_at' => now()->addDays(2), 'paid_until' => null]);
        $this->ownerFor($business);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();
        Mail::assertSentCount(1);

        // Semanas despues, el negocio activa un plan pago que vuelve a
        // vencer dentro de la ventana: la notificacion anterior quedo bien
        // atras de esta nueva ventana, tiene que dispararse de nuevo.
        Carbon::setTestNow(now()->addDays(20));
        $business->update(['paid_until' => now()->addDays(2)]);

        try {
            $this->artisan('subscriptions:notify-expiring')->assertSuccessful();
            Mail::assertSentCount(2);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_respects_the_days_option(): void
    {
        Mail::fake();
        $business = Business::factory()->create(['trial_ends_at' => now()->addDays(5), 'paid_until' => null]);
        $this->ownerFor($business);

        $this->artisan('subscriptions:notify-expiring', ['--days' => 7])->assertSuccessful();

        Mail::assertSentCount(1);
    }
}
