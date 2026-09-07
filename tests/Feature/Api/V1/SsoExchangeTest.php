<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

/**
 * Canje de una asercion de nexolu-auth por un token de Sanctum.
 *
 * Lo que estas pruebas defienden, en orden de importancia:
 *
 * 1. Que el acceso de los NEGOCIOS no cambie. El canje es aditivo: /v1/login
 *    sigue igual y solo el superadmin puede canjear.
 * 2. La frontera entre productos: una asercion de la agenda no abre el POS.
 * 3. La reversibilidad: sin llave configurada el canje muere y el login
 *    sigue vivo.
 */
class SsoExchangeTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    private const ISSUER = 'https://auth.nexolu.test';

    private const AUDIENCE = 'nexolu-pos-api';

    private const KID = 'test-kid';

    private static string $privateKey;

    private static string $publicKey;

    private static string $otraPrivateKey;

    private User $superadmin;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Un par por clase: 2048 bits cuestan cientos de milisegundos y lo
        // que hay que aislar entre pruebas es la base, no las llaves.
        [self::$privateKey, self::$publicKey] = self::generarPar();
        [self::$otraPrivateKey] = self::generarPar();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function generarPar(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $private);
        $public = openssl_pkey_get_details($resource)['key'];

        return [$private, $public];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = $this->superadmin([
            'business_id' => null,
            'password' => Hash::make('secret123'),
        ]);

        config()->set('services.nexolu_auth.issuer', self::ISSUER);
        config()->set('services.nexolu_auth.audience', self::AUDIENCE);
        config()->set('services.nexolu_auth.email_fallback', true);
        config()->set('services.nexolu_auth.public_keys', json_encode([
            self::KID => base64_encode(self::$publicKey),
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function asercion(array $overrides = [], ?string $key = null): string
    {
        $now = time();

        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'identidad-de-prueba',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 120,
            'jti' => Str::random(32),
            'typ' => 'sso',
            'email' => $this->superadmin->email,
            'name' => 'Plataforma',
            'account' => ['user_id' => (string) $this->superadmin->id],
            'scope' => 'superadmin',
        ], $overrides);

        return JWT::encode($claims, $key ?? self::$privateKey, 'RS256', self::KID);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function canjear(array $overrides = [], ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/sso/exchange', [
            'assertion' => $this->asercion($overrides, $key),
        ]);
    }

    // ---- Lo que NO debe cambiar para los negocios ----

    public function test_el_login_de_un_negocio_no_cambia_en_nada(): void
    {
        $usuario = User::factory()->create(['password' => Hash::make('secret123')]);

        $this->postJson('/api/v1/login', [
            'email' => $usuario->email,
            'password' => 'secret123',
            'device_name' => 'phpunit',
        ])->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email']]);
    }

    public function test_un_usuario_de_negocio_no_puede_canjear(): void
    {
        // Aunque alguien le robara una asercion a su nombre: en la Fase 1
        // solo el superadmin canjea.
        $usuario = User::factory()->create();

        $this->canjear(['account' => ['user_id' => (string) $usuario->id]])->assertForbidden();
    }

    public function test_un_admin_de_negocio_tampoco_canjea(): void
    {
        $admin = User::factory()->create(['is_business_owner' => true]);
        $admin->assignRole('admin');

        $this->canjear(['account' => ['user_id' => (string) $admin->id]])->assertForbidden();
    }

    // ---- El camino del superadmin ----

    public function test_una_asercion_valida_devuelve_un_token_usable(): void
    {
        $response = $this->canjear();

        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('id', $this->superadmin->id);
    }

    public function test_la_respuesta_tiene_la_misma_forma_que_el_login(): void
    {
        // El front hace lo mismo con las dos; si divergen, una de las dos
        // rutas rompe en silencio.
        $delLogin = $this->postJson('/api/v1/login', [
            'email' => $this->superadmin->email,
            'password' => 'secret123',
            'device_name' => 'phpunit',
        ])->assertOk()->json();

        $delCanje = $this->canjear()->assertOk()->json();

        $this->assertSame(array_keys($delLogin), array_keys($delCanje));
        $this->assertSame($delLogin['user'], $delCanje['user']);
    }

    public function test_el_token_del_canje_entra_al_panel_de_superadmin(): void
    {
        // El PAT del canje tiene que servir donde sirve el del login: si el
        // rol no viajara con el usuario, esto daria 403.
        $token = $this->canjear()->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/superadmin/businesses')
            ->assertOk();
    }

    public function test_resuelve_por_el_vinculo_explicito(): void
    {
        // Correo distinto del de la fila: si resolviera por correo esto
        // fallaria. PUT /v1/me deja cambiar el propio correo, por eso el
        // vinculo manda.
        $this->canjear(['email' => 'otro-correo@nexolu.test'])
            ->assertOk()
            ->assertJsonPath('user.id', $this->superadmin->id);
    }

    public function test_sin_vinculo_cae_al_fallback_por_correo(): void
    {
        $this->canjear(['account' => null])
            ->assertOk()
            ->assertJsonPath('user.id', $this->superadmin->id);
    }

    public function test_con_el_fallback_apagado_y_sin_vinculo_da_403(): void
    {
        config()->set('services.nexolu_auth.email_fallback', false);

        $this->canjear(['account' => null])->assertForbidden();
    }

    public function test_una_identidad_sin_cuenta_aca_da_403_y_no_401(): void
    {
        // 403 terminal: con 401 el front creeria que la asercion vencio,
        // rebotaria a nexolu-auth (cookie viva), recibiria otra, y el
        // usuario quedaria en un bucle sin ver nunca un formulario.
        $this->canjear(['account' => null, 'email' => 'nadie@nexolu.test'])->assertForbidden();
    }

    public function test_un_superadmin_desactivado_no_canjea(): void
    {
        $this->superadmin->update(['is_active' => false]);

        $this->canjear()->assertForbidden();
    }

    // ---- La frontera entre productos ----

    public function test_una_asercion_de_la_agenda_no_abre_el_pos(): void
    {
        // El test mas importante del archivo: JWT::decode() NO valida `aud`
        // por su cuenta. Si alguien quita el chequeo manual del
        // verificador, esto es lo unico que lo detecta.
        $this->canjear(['aud' => 'nexolu-spa-api'])->assertUnauthorized();
    }

    public function test_un_emisor_distinto_no_sirve(): void
    {
        // Mismo caso: `iss` tampoco lo valida la libreria.
        $this->canjear(['iss' => 'https://impostor.example.com'])->assertUnauthorized();
    }

    public function test_una_asercion_vencida_no_sirve(): void
    {
        $this->canjear(['iat' => time() - 600, 'nbf' => time() - 600, 'exp' => time() - 480])
            ->assertUnauthorized();
    }

    public function test_una_asercion_de_otra_llave_no_sirve(): void
    {
        $this->canjear([], self::$otraPrivateKey)->assertUnauthorized();
    }

    public function test_un_tipo_distinto_de_asercion_no_sirve(): void
    {
        $this->canjear(['typ' => 'otra-cosa'])->assertUnauthorized();
    }

    public function test_alg_none_no_sirve(): void
    {
        $header = rtrim(strtr(base64_encode(json_encode(
            ['alg' => 'none', 'typ' => 'JWT', 'kid' => self::KID]
        )), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'iss' => self::ISSUER, 'aud' => self::AUDIENCE, 'sub' => 'x',
            'iat' => time(), 'exp' => time() + 120, 'jti' => Str::random(32),
            'typ' => 'sso', 'email' => $this->superadmin->email,
        ])), '+/', '-_'), '=');

        $this->postJson('/api/v1/auth/sso/exchange', [
            'assertion' => "{$header}.{$payload}.",
        ])->assertUnauthorized();
    }

    public function test_una_firma_alterada_no_sirve(): void
    {
        $alterada = substr($this->asercion(), 0, -6).'AAAAAA';

        $this->postJson('/api/v1/auth/sso/exchange', ['assertion' => $alterada])
            ->assertUnauthorized();
    }

    public function test_la_misma_asercion_no_se_canjea_dos_veces(): void
    {
        // Queda en el historial del navegador; volver atras no puede volver
        // a entrar.
        $cuerpo = ['assertion' => $this->asercion()];

        $this->postJson('/api/v1/auth/sso/exchange', $cuerpo)->assertOk();
        $this->postJson('/api/v1/auth/sso/exchange', $cuerpo)->assertUnauthorized();
    }

    // ---- El interruptor ----

    public function test_sin_llave_configurada_el_login_sigue_vivo(): void
    {
        // LA prueba de reversibilidad: vaciar NEXOLU_AUTH_PUBLIC_KEYS apaga
        // el SSO sin dejar a NADIE fuera del POS.
        config()->set('services.nexolu_auth.public_keys', '{}');

        $this->canjear()->assertStatus(503);

        $this->postJson('/api/v1/login', [
            'email' => $this->superadmin->email,
            'password' => 'secret123',
            'device_name' => 'phpunit',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_un_pem_mal_pegado_nombra_la_variable(): void
    {
        config()->set('services.nexolu_auth.public_keys', json_encode([
            self::KID => 'esto-no-es-base64!!',
        ]));

        $this->canjear()
            ->assertStatus(503)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'NEXOLU_AUTH_PUBLIC_KEYS'));
    }
}
