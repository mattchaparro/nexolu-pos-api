<?php

namespace Tests\Feature\Api\V1;

use App\Mail\ResetPasswordMail;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use DatabaseTransactions;

    public function test_forgot_password_sends_the_reset_email_for_an_existing_user(): void
    {
        Mail::fake();
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'Si el correo existe, te enviamos un enlace para restablecer tu contraseña.');

        Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use ($user) {
            return $mail->hasTo($user->email) && $mail->user->is($user);
        });
    }

    /** El link del correo apunta al frontend (SPA separada), no a esta API. */
    public function test_forgot_password_email_links_to_the_frontend(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk();

        Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use ($user) {
            $rendered = $mail->render();

            return str_contains($rendered, rtrim(config('app.frontend_url'), '/').'/restablecer-contrasena?')
                && str_contains($rendered, 'token=')
                && str_contains($rendered, urlencode($user->email));
        });
    }

    public function test_forgot_password_returns_the_same_generic_message_for_an_unknown_email(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/forgot-password', ['email' => 'no-existe@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'Si el correo existe, te enviamos un enlace para restablecer tu contraseña.');

        Mail::assertNothingSent();
    }

    public function test_forgot_password_requires_a_valid_email(): void
    {
        $this->postJson('/api/v1/forgot-password', ['email' => 'no-es-un-correo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_reset_password_updates_the_password_with_a_valid_token(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => Hash::make('old-password')]);
        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk();

        $token = $this->capturedResetToken();

        $this->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk()->assertJsonPath('message', 'Tu contraseña se actualizó correctamente.');

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));

        // El endpoint de login sigue siendo la fuente de verdad de que la
        // nueva contraseña realmente funciona, no solo que el hash cambio.
        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'new-password-123',
            'device_name' => 'phpunit',
        ])->assertOk();
    }

    public function test_reset_password_rejects_an_invalid_token(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $this->postJson('/api/v1/reset-password', [
            'token' => 'un-token-que-no-existe',
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_reset_password_requires_password_confirmation_to_match(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk();
        $token = $this->capturedResetToken();

        $this->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'does-not-match',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    private function capturedResetToken(): string
    {
        $captured = null;
        Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use (&$captured) {
            $captured = $mail->token;

            return true;
        });

        $this->assertNotNull($captured);

        return $captured;
    }
}
