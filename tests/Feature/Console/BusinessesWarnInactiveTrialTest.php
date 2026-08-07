<?php

namespace Tests\Feature\Console;

use App\Mail\InactiveTrialWarningMail;
use App\Models\Business;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BusinessesWarnInactiveTrialTest extends TestCase
{
    use DatabaseTransactions;

    private function businessWithAdmin(array $attributes = []): Business
    {
        $business = Business::factory()->create(array_merge(['paid_until' => null], $attributes));
        $admin = User::factory()->create(['business_id' => $business->id]);
        $admin->assignRole('admin');

        return $business;
    }

    public function test_warns_a_business_with_an_expired_trial_and_no_paid_plan(): void
    {
        Mail::fake();
        $business = $this->businessWithAdmin(['trial_ends_at' => now()->subDays(61)]);

        $this->artisan('businesses:warn-inactive-trial')->assertSuccessful();

        Mail::assertSent(InactiveTrialWarningMail::class, fn ($mail) => $mail->hasTo($business->users()->first()->email));
    }

    public function test_does_not_warn_a_business_still_within_the_grace_period(): void
    {
        Mail::fake();
        $this->businessWithAdmin(['trial_ends_at' => now()->subDays(10)]);

        $this->artisan('businesses:warn-inactive-trial')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_warn_a_business_with_an_active_paid_plan(): void
    {
        Mail::fake();
        $this->businessWithAdmin(['trial_ends_at' => now()->subDays(90), 'paid_until' => now()->addMonth()]);

        $this->artisan('businesses:warn-inactive-trial')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_respects_the_resend_cooldown(): void
    {
        Mail::fake();
        $business = $this->businessWithAdmin(['trial_ends_at' => now()->subDays(90)]);
        EmailLog::create([
            'business_id' => $business->id, 'type' => 'inactive_trial_warning',
            'to_email' => 'x@example.com', 'subject' => 'x', 'status' => EmailLog::STATUS_SENT,
        ]);

        $this->artisan('businesses:warn-inactive-trial')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_resends_after_the_cooldown_expires(): void
    {
        Mail::fake();
        $business = $this->businessWithAdmin(['trial_ends_at' => now()->subDays(90)]);
        $old = EmailLog::create([
            'business_id' => $business->id, 'type' => 'inactive_trial_warning',
            'to_email' => 'x@example.com', 'subject' => 'x', 'status' => EmailLog::STATUS_SENT,
        ]);
        $old->forceFill(['created_at' => now()->subDays(35)])->save();

        $this->artisan('businesses:warn-inactive-trial')->assertSuccessful();

        Mail::assertSent(InactiveTrialWarningMail::class);
    }

    public function test_skips_a_business_without_an_admin(): void
    {
        Mail::fake();
        Business::factory()->create(['trial_ends_at' => now()->subDays(90), 'paid_until' => null]);

        $this->artisan('businesses:warn-inactive-trial')->assertSuccessful();

        Mail::assertNothingSent();
    }
}
