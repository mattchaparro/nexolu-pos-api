<?php

namespace Tests\Feature\Api\V1;

use App\Mail\WelcomeMail;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registering_creates_a_business_and_its_owner_and_logs_them_in(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/register', [
            'business_name' => 'Tienda Nueva',
            'owner_name' => 'Ana Gomez',
            'email' => 'ana@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'device_name' => 'phpunit',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'business_id']])
            ->assertJsonPath('user.email', 'ana@example.com')
            ->assertJsonPath('user.is_business_owner', true)
            ->assertJsonPath('user.roles', ['admin']);

        $user = User::where('email', 'ana@example.com')->first();
        $this->assertNotNull($user->business_id);
        $this->assertTrue($user->is_active);

        $business = Business::find($user->business_id);
        $this->assertSame('Tienda Nueva', $business->name);
        $this->assertSame('Ana Gomez', $business->owner_name);
        $this->assertNotNull($business->trial_ends_at);

        Mail::assertSent(WelcomeMail::class, fn ($mail) => $mail->hasTo('ana@example.com'));
    }

    public function test_registration_can_capture_the_business_whatsapp_number(): void
    {
        $this->postJson('/api/v1/register', [
            'business_name' => 'Tienda WA',
            'owner_name' => 'Duenno',
            'email' => 'wa@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'whatsapp_number' => '+573001234567',
            'device_name' => 'phpunit',
        ])->assertCreated();

        $this->assertDatabaseHas('businesses', [
            'name' => 'Tienda WA',
            'whatsapp_number' => '+573001234567',
        ]);
    }

    public function test_default_setup_mode_is_retail_with_the_basic_plan(): void
    {
        $this->postJson('/api/v1/register', [
            'business_name' => 'Tienda Retail',
            'owner_name' => 'Carlos Ruiz',
            'email' => 'carlos@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'device_name' => 'phpunit',
        ])->assertCreated();

        $business = Business::where('name', 'Tienda Retail')->first();
        $this->assertSame('basic', $business->subscription_plan);
        $this->assertFalse($business->feature_flags['open_tabs']);
    }

    public function test_food_service_setup_mode_enables_open_tabs_on_the_full_plan(): void
    {
        $this->postJson('/api/v1/register', [
            'business_name' => 'Restaurante Full',
            'owner_name' => 'Maria Lopez',
            'email' => 'maria@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'setup_mode' => 'food_service',
            'device_name' => 'phpunit',
        ])->assertCreated();

        $business = Business::where('name', 'Restaurante Full')->first();
        $this->assertSame('full', $business->subscription_plan);
        $this->assertTrue($business->feature_flags['open_tabs']);
    }

    public function test_the_new_owner_can_log_in_with_the_password_just_registered(): void
    {
        $this->postJson('/api/v1/register', [
            'business_name' => 'Tienda Login',
            'owner_name' => 'Pedro Diaz',
            'email' => 'pedro@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'device_name' => 'phpunit',
        ])->assertCreated();

        $this->postJson('/api/v1/login', [
            'email' => 'pedro@example.com',
            'password' => 'secret123',
            'device_name' => 'phpunit',
        ])->assertOk();
    }

    public function test_registering_with_an_email_already_in_use_is_rejected(): void
    {
        $existing = User::factory()->create(['email' => 'duplicado@example.com']);

        $this->postJson('/api/v1/register', [
            'business_name' => 'Otra Tienda',
            'owner_name' => 'Nuevo Dueño',
            'email' => $existing->email,
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'device_name' => 'phpunit',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_registering_with_a_short_password_is_rejected(): void
    {
        $this->postJson('/api/v1/register', [
            'business_name' => 'Tienda X',
            'owner_name' => 'Dueño X',
            'email' => 'x@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
            'device_name' => 'phpunit',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registering_with_an_invalid_setup_mode_is_rejected(): void
    {
        $this->postJson('/api/v1/register', [
            'business_name' => 'Tienda Y',
            'owner_name' => 'Dueño Y',
            'email' => 'y@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'setup_mode' => 'not-a-real-mode',
            'device_name' => 'phpunit',
        ])->assertStatus(422)->assertJsonValidationErrors('setup_mode');
    }
}
