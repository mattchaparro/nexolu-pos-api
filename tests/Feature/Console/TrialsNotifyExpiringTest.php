<?php

namespace Tests\Feature\Console;

use App\Mail\SubscriptionExpiringMail;
use App\Models\Business;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TrialsNotifyExpiringTest extends TestCase
{
    use DatabaseTransactions;

    private function ownerFor(Business $business): User
    {
        $owner = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $owner->assignRole('admin');

        return $owner;
    }

    public function test_notifies_a_business_whose_trial_expires_in_three_days(): void
    {
        Mail::fake();
        $business = Business::factory()->create(['trial_ends_at' => now()->addDays(3), 'paid_until' => null]);
        $owner = $this->ownerFor($business);

        $this->artisan('trials:notify-expiring')->assertSuccessful();

        Mail::assertSent(SubscriptionExpiringMail::class, fn ($mail) => $mail->hasTo($owner->email)
            && ! $mail->isPaidSubscription
            && $mail->emailType === 'trial_expiring_3d');
    }

    public function test_notifies_a_business_whose_trial_expires_tomorrow(): void
    {
        Mail::fake();
        $business = Business::factory()->create(['trial_ends_at' => now()->addDay(), 'paid_until' => null]);
        $owner = $this->ownerFor($business);

        $this->artisan('trials:notify-expiring')->assertSuccessful();

        Mail::assertSent(SubscriptionExpiringMail::class, fn ($mail) => $mail->hasTo($owner->email)
            && ! $mail->isPaidSubscription
            && $mail->emailType === 'trial_expiring_1d');
    }

    public function test_does_not_notify_a_business_outside_either_window(): void
    {
        Mail::fake();
        Business::factory()->create(['trial_ends_at' => now()->addDays(10), 'paid_until' => null]);

        $this->artisan('trials:notify-expiring')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_notify_a_business_on_a_paid_subscription(): void
    {
        Mail::fake();
        Business::factory()->create(['trial_ends_at' => now()->addDay(), 'paid_until' => now()->addMonth()]);

        $this->artisan('trials:notify-expiring')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_notify_an_inactive_business(): void
    {
        Mail::fake();
        Business::factory()->create(['active' => false, 'trial_ends_at' => now()->addDay(), 'paid_until' => null]);

        $this->artisan('trials:notify-expiring')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_resend_the_same_window_type_within_two_days(): void
    {
        Mail::fake();
        $business = Business::factory()->create(['trial_ends_at' => now()->addDays(3), 'paid_until' => null]);
        $this->ownerFor($business);

        EmailLog::create([
            'business_id' => $business->id,
            'type' => 'trial_expiring_3d',
            'to_email' => 'owner@example.com',
            'subject' => 'Tu prueba gratuita de Nexolú vence pronto',
            'status' => EmailLog::STATUS_SENT,
        ]);

        $this->artisan('trials:notify-expiring')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_sends_both_windows_independently_for_different_businesses(): void
    {
        Mail::fake();
        $threeDayBusiness = Business::factory()->create(['trial_ends_at' => now()->addDays(3), 'paid_until' => null]);
        $this->ownerFor($threeDayBusiness);
        $oneDayBusiness = Business::factory()->create(['trial_ends_at' => now()->addDay(), 'paid_until' => null]);
        $this->ownerFor($oneDayBusiness);

        $this->artisan('trials:notify-expiring')->assertSuccessful();

        Mail::assertSentCount(2);
    }
}
