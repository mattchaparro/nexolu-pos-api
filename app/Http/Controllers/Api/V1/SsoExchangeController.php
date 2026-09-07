<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SsoExchangeRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Auth\InvalidAssertion;
use App\Support\Auth\NexoluAuthAssertion;
use App\Support\Auth\SsoNotConfigured;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Canjea una asercion de nexolu-auth por un token de Sanctum normal.
 *
 * ESTE ENDPOINT ES ADITIVO. No toca `AuthController::login()` ni nada del
 * camino por el que entran hoy los negocios: quien no use el SSO no nota
 * ninguna diferencia. En la Fase 1 el unico que puede canjear es el
 * superadmin de la plataforma (ver assertEligible), asi que una asercion a
 * nombre de un usuario de un negocio no sirve para nada aca.
 *
 * Despues del canje nexolu-auth desaparece del camino: el token es el mismo
 * PAT que emite login(), con su misma expiracion, y auth:sanctum, spatie,
 * X-Branch-Id e impersonacion siguen igual. Por eso es un controlador y no
 * un middleware: verificar el JWT en cada peticion meteria la rotacion de
 * llaves en el camino caliente de todos los negocios.
 */
class SsoExchangeController extends Controller
{
    public function __invoke(SsoExchangeRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $assertion = NexoluAuthAssertion::verify($data['assertion']);
        } catch (SsoNotConfigured $error) {
            // 503: el SSO no esta habilitado aca. POST /v1/login sigue
            // funcionando igual, que es justo el punto del interruptor.
            abort(503, $error->getMessage());
        } catch (InvalidAssertion $error) {
            Log::warning('sso.asercion_invalida', ['motivo' => $error->getMessage()]);
            abort(401, 'La asercion no es valida.');
        }

        $user = $this->resolve($assertion);

        if (! $user) {
            // 403 y NO 401. Con 401 el frontend creeria que la asercion
            // vencio, rebotaria a nexolu-auth (que tiene la cookie viva),
            // recibiria otra asercion, volveria a fallar igual... y el
            // usuario quedaria en un bucle sin ver nunca un formulario.
            Log::warning('sso.cuenta_no_vinculada', ['email' => $assertion->email]);
            abort(403, 'Esa identidad no tiene una cuenta en esta aplicacion.');
        }

        $this->assertEligible($user);

        $token = $user->createToken('sso-nexolu-auth')->plainTextToken;

        // Antes de auditar: la ruta es publica, asi que sin esto
        // AuditLogger guardaria user_id NULL y el rastro no diria quien
        // entro. Solo afecta a esta peticion, que termina aca.
        auth()->setUser($user);
        AuditLogger::log('auth.sso.exchanged', [
            'identity_id' => $assertion->subject,
            'jti' => $assertion->jti,
        ]);

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('roles')),
        ]);
    }

    /**
     * Resuelve por el vinculo explicito y, solo si no hay, por correo.
     *
     * El orden importa: `account.user_id` viene de `linked_accounts` en
     * nexolu-auth y es la fuente autoritativa. Cruzar por correo no puede
     * ser lo primero porque `UpdateProfileRequest` deja a cualquier usuario
     * cambiar el suyo desde PUT /v1/me -- editar tu perfil te desconectaria
     * del SSO en silencio.
     *
     * El log dice por CUAL de los dos caminos resolvio; sin eso nadie se
     * entera nunca de que el mapeo esta roto y el fallback lo esta tapando.
     */
    private function resolve(NexoluAuthAssertion $assertion): ?User
    {
        if ($assertion->externalUserId !== null) {
            $user = User::find($assertion->externalUserId);

            if ($user) {
                Log::info('sso.usuario_resuelto', ['via' => 'linked_account', 'user_id' => $user->id]);

                return $user;
            }
        }

        if (! config('services.nexolu_auth.email_fallback')) {
            return null;
        }

        $user = User::where('email', $assertion->email)->first();

        if ($user) {
            Log::warning('sso.usuario_resuelto', ['via' => 'email_fallback', 'user_id' => $user->id]);
        }

        return $user;
    }

    /**
     * La UNICA linea que difiere del canje de nexolu-spa-api, donde el
     * superadmin es una columna booleana y aca es un rol de spatie bajo el
     * guard `web` (ver EnsureSuperAdmin y User::getDefaultGuardName()).
     *
     * Es una compuerta dura, no una pista: en la Fase 1 solo el superadmin
     * canjea, y por eso el login de los negocios no cambia en nada. El
     * claim `scope` de la asercion no se mira -- nexolu-auth dice quien
     * eres, esta API decide que puedes.
     */
    private function assertEligible(User $user): void
    {
        abort_unless($user->hasRole('superadmin'), 403, 'Esa identidad no puede entrar por aca.');
        abort_unless($user->is_active, 403, 'Esta cuenta esta desactivada.');
    }
}
