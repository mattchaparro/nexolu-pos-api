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
            'whatsapp_number' => '3001234567',
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

    public function test_whatsapp_number_is_required(): void
    {
        $this->postJson('/api/v1/register', [
            'business_name' => 'Tienda Sin WhatsApp',
            'owner_name' => 'Dueño Z',
            'email' => 'sinwa@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'device_name' => 'phpunit',
        ])->assertStatus(422)->assertJsonValidationErrors('whatsapp_number');
    }

    public function test_phone_defaults_to_the_whatsapp_number_when_not_given_separately(): void
    {
        $this->postJson('/api/v1/register', [
            'business_name' => 'Tienda Un Solo Numero',
            'owner_name' => 'Dueño Unico',
            'email' => 'unico@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'whatsapp_number' => '3009876543',
            'device_name' => 'phpunit',
        ])->assertCreated();

        $this->assertDatabaseHas('businesses', [
            'name' => 'Tienda Un Solo Numero',
            'phone' => '3009876543',
            'whatsapp_number' => '3009876543',
        ]);
    }

    public function test_phone_can_be_set_separately_from_the_whatsapp_number(): void
    {
        $this->postJson('/api/v1/register', [
            'business_name' => 'Tienda Dos Numeros',
            'owner_name' => 'Dueño Doble',
            'email' => 'doble@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'whatsapp_number' => '3001112233',
            'phone' => '6015551234',
            'device_name' => 'phpunit',
        ])->assertCreated();

        $this->assertDatabaseHas('businesses', [
            'name' => 'Tienda Dos Numeros',
            'phone' => '6015551234',
            'whatsapp_number' => '3001112233',
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
            'whatsapp_number' => '3001234567',
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
            'whatsapp_number' => '3001234567',
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
            'whatsapp_number' => '3001234567',
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
            'whatsapp_number' => '3001234567',
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
            'whatsapp_number' => '3001234567',
            'device_name' => 'phpunit',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registering_with_a_plan_can_turn_off_a_flag_the_plan_includes_by_default(): void
    {
        $this->postJson('/api/v1/register', [
            'business_name' => 'Tienda Full Sin Recordatorios',
            'owner_name' => 'Laura Ruiz',
            'email' => 'laura@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'plan' => 'full',
            'feature_flags' => ['reminders' => false],
            'whatsapp_number' => '3001234567',
            'device_name' => 'phpunit',
        ])->assertCreated();

        $business = Business::where('name', 'Tienda Full Sin Recordatorios')->first();
        $this->assertSame('full', $business->subscription_plan);
        $this->assertFalse($business->feature_flags['reminders']);
        // El resto de defaults del plan Full siguen intactos.
        $this->assertTrue($business->feature_flags['open_tabs']);
    }

    public function test_registering_with_a_plan_cannot_turn_on_a_flag_the_plan_excludes(): void
    {
        $this->postJson('/api/v1/register', [
            'business_name' => 'Tienda Basica Con Trampa',
            'owner_name' => 'Jorge Diaz',
            'email' => 'jorge@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'plan' => 'basic',
            // open_tabs no viene encendido por defecto en basic - intentar
            // prenderlo desde el registro publico debe ser ignorado.
            'feature_flags' => ['open_tabs' => true],
            'whatsapp_number' => '3001234567',
            'device_name' => 'phpunit',
        ])->assertCreated();

        $business = Business::where('name', 'Tienda Basica Con Trampa')->first();
        $this->assertSame('basic', $business->subscription_plan);
        $this->assertFalse($business->feature_flags['open_tabs']);
    }

    public function test_the_full_plan_includes_the_online_store_and_variants_from_the_registration(): void
    {
        $this->postJson('/api/v1/register', [
            'business_name' => 'Boutique Full',
            'owner_name' => 'Sara Mora',
            'email' => 'sara@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'plan' => 'full',
            'whatsapp_number' => '3001234567',
            'device_name' => 'phpunit',
        ])->assertCreated();

        $business = Business::where('name', 'Boutique Full')->first();
        $this->assertTrue($business->feature_flags['online_store']);
        $this->assertTrue($business->feature_flags['variants']);
        $this->assertTrue($business->hasFeature('online_store'));
    }

    public function test_the_basic_plan_cannot_get_the_online_store_or_variants_from_the_registration(): void
    {
        $this->postJson('/api/v1/register', [
            'business_name' => 'Tienda Basica Ambiciosa',
            'owner_name' => 'Ivan Paz',
            'email' => 'ivan@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'plan' => 'basic',
            // Ninguna de las dos viene en Basico: prenderlas desde el registro
            // publico se ignora, subir de plan es el unico camino.
            'feature_flags' => ['online_store' => true, 'variants' => true],
            'whatsapp_number' => '3001234567',
            'device_name' => 'phpunit',
        ])->assertCreated();

        $business = Business::where('name', 'Tienda Basica Ambiciosa')->first();
        $this->assertFalse($business->feature_flags['online_store']);
        $this->assertFalse($business->feature_flags['variants']);
    }

    public function test_a_full_plan_business_can_opt_out_of_the_online_store_while_registering(): void
    {
        $this->postJson('/api/v1/register', [
            'business_name' => 'Restaurante Sin Tienda',
            'owner_name' => 'Hugo Vera',
            'email' => 'hugo@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'plan' => 'full',
            'feature_flags' => ['online_store' => false],
            'whatsapp_number' => '3001234567',
            'device_name' => 'phpunit',
        ])->assertCreated();

        $business = Business::where('name', 'Restaurante Sin Tienda')->first();
        $this->assertFalse($business->feature_flags['online_store']);
        $this->assertTrue($business->feature_flags['variants']);
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
            'whatsapp_number' => '3001234567',
            'device_name' => 'phpunit',
        ])->assertStatus(422)->assertJsonValidationErrors('setup_mode');
    }
}
