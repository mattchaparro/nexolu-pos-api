<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Ver como" a un usuario de un negocio, para soporte. En una API stateless
 * no hay sesion que cambiar (a diferencia del legacy, que hacia login/logout
 * de sesion): impersonar es simplemente emitir un token Sanctum a nombre del
 * usuario destino. El superadmin conserva su propio token intacto. No hay un
 * endpoint "stop" dedicado - salir es un POST /logout normal usando el token
 * de impersonacion como bearer (asi lo hace el front en
 * AuthStore::stopImpersonating), que ya revoca ese token server-side
 * (AuthController::logout -> currentAccessToken()->delete()); ese mismo
 * logout detecta el nombre del token para auditar el cierre, ver abajo.
 */
class ImpersonateController extends Controller
{
    /** Prefijo del nombre del token Sanctum de impersonacion, seguido del id del superadmin que impersona. */
    public const TOKEN_NAME_PREFIX = 'impersonation-by-';

    public function start(Request $request, User $user): array
    {
        if ($user->hasRole('superadmin')) {
            throw ValidationException::withMessages(['user' => 'No puedes impersonar a otro superadmin.']);
        }

        $token = $user->createToken(self::TOKEN_NAME_PREFIX.$request->user()->id)->plainTextToken;

        AuditLogger::log('superadmin.impersonation.started', [
            'business_id' => $user->business_id,
            'impersonated_user_id' => $user->id,
            // A diferencia de cualquier otra accion hecha DURANTE una
            // impersonacion (que AuditLogger::log() marca solo mirando el
            // token de la request ACTUAL), este request en particular
            // todavia esta autenticado con el token real del superadmin -
            // el de impersonacion recien se crea arriba, no existe todavia
            // como token "actual" de esta request. Sin pasarlo a mano aca,
            // este evento puntual quedaba sin el marcador que
            // AuditLogQuery::forBusiness() usa para excluir de la
            // auditoria del dueño lo que no es suyo (bug real reportado:
            // "impersonar" aparecia en su auditoria, a diferencia del
            // evento .ended, que si queda marcado solo porque logout()
            // corre con el token de impersonacion todavia activo).
            'impersonated_by_superadmin_id' => $request->user()->id,
        ]);

        return [
            'token' => $token,
            'user' => new UserResource($user->load('roles')),
        ];
    }
}
