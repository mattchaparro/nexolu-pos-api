<?php

namespace Tests\Feature\Console;

use App\Mail\TrialWinbackMail;
use App\Models\Business;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BusinessesSendTrialWinbackTest extends TestCase
{
    use DatabaseTransactions;

    private function businessWithAdmin(array $attributes = []): Business
    {
        $business = Business::factory()->create(array_merge(['paid_until' => null], $attributes));
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return $business;
    }

    public function test_sends_winback_and_extends_the_trial_for_an_eligible_business(): void
    {
        Mail::fake();
        $business = $this->businessWithAdmin(['trial_ends_at' => now()->subDays(10)]);
        $originalTrialEnd = $business->trial_ends_at;

        $this->artisan('businesses:send-trial-winback')->assertSuccessful();

        Mail::assertSent(TrialWinbackMail::class, fn ($mail) => $mail->hasTo($business->users()->first()->email));
        $this->assertTrue($business->fresh()->trial_ends_at->gt($originalTrialEnd));
    }

    public function test_does_not_send_before_the_minimum_expired_days(): void
    {
        Mail::fake();
        $this->businessWithAdmin(['trial_ends_at' => now()->subDays(1)]);

        $this->artisan('businesses:send-trial-winback')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_send_after_the_maximum_expired_days(): void
    {
        Mail::fake();
        $this->businessWithAdmin(['trial_ends_at' => now()->subDays(90)]);

        $this->artisan('businesses:send-trial-winback')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_send_to_a_business_with_an_active_paid_plan(): void
    {
        Mail::fake();
        $this->businessWithAdmin(['trial_ends_at' => now()->subDays(10), 'paid_until' => now()->addMonth()]);

        $this->artisan('businesses:send-trial-winback')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_never_sends_a_second_winback_to_the_same_business(): void
    {
        Mail::fake();
        $business = $this->businessWithAdmin(['trial_ends_at' => now()->subDays(10)]);
        EmailLog::create([
            'business_id' => $business->id, 'type' => 'trial_winback',
            'to_email' => 'x@example.com', 'subject' => 'x', 'status' => EmailLog::STATUS_SENT,
        ]);

        $this->artisan('businesses:send-trial-winback')->assertSuccessful();

        Mail::assertNothingSent();
    }
}
