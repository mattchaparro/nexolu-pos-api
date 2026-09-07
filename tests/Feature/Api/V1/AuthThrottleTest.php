<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Las puertas publicas de auth tienen que aguantar un script, no solo a un
 * humano tecleando. Hasta 2026-09-07 no tenian throttle: se podian probar
 * contraseñas a la velocidad de la red, y cada intento corre bcrypt (caro a
 * proposito), asi que servia igual para robar credenciales que para tumbar
 * el servidor de un negocio en vivo.
 */
class AuthThrottleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Los limitadores viven en cache: sin limpiarla, el contador de un
        // test se arrastra al siguiente.
        RateLimiter::clear('login');
        cache()->flush();
    }

    private function attemptLogin(string $email, string $password = 'wrong-password'): TestResponse
    {
        return $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => $password,
            'device_name' => 'test',
        ]);
    }

    public function test_brute_force_against_one_account_gets_blocked(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'email' => 'cajero@negocio.test',
            'password' => 'la-clave-correcta',
        ]);

        // 5 intentos fallidos por minuto es el limite por credencial.
        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin($user->email)->assertStatus(401);
        }

        $blocked = $this->attemptLogin($user->email);
        $blocked->assertStatus(429);
        $blocked->assertJsonPath('message', 'Demasiados intentos. Espera un momento y vuelve a intentar.');
    }

    public function test_the_block_survives_guessing_the_right_password(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'email' => 'duenio@negocio.test',
            'password' => 'la-clave-correcta',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin($user->email)->assertStatus(401);
        }

        // Aunque acierte la clave, ya agoto los intentos: el throttle corre
        // ANTES del controlador. Esto es lo que hace inutil la fuerza bruta.
        $this->attemptLogin($user->email, 'la-clave-correcta')->assertStatus(429);
    }

    public function test_a_normal_login_is_not_affected(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'email' => 'normal@negocio.test',
            'password' => 'la-clave-correcta',
        ]);

        // Un cajero que se equivoca un par de veces y despues acierta sigue
        // entrando - el limite no puede estorbar el uso real.
        $this->attemptLogin($user->email)->assertStatus(401);
        $this->attemptLogin($user->email)->assertStatus(401);

        $this->attemptLogin($user->email, 'la-clave-correcta')
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_password_recovery_is_throttled_per_email(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id, 'email' => 'victima@negocio.test']);

        // 5 por hora por email: evita usar la app para bombardearle el correo
        // a una persona.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/forgot-password', ['email' => $user->email]);
        }

        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])
            ->assertStatus(429);
    }
}
