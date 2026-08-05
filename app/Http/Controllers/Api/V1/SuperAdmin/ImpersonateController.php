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
 * usuario destino. El superadmin conserva su propio token intacto - volver
 * es solo dejar de usar el token de impersonacion, no hay endpoint "stop".
 */
class ImpersonateController extends Controller
{
    public function start(Request $request, User $user): array
    {
        if ($user->hasRole('superadmin')) {
            throw ValidationException::withMessages(['user' => 'No puedes impersonar a otro superadmin.']);
        }

        $token = $user->createToken('impersonation-by-'.$request->user()->id)->plainTextToken;

        AuditLogger::log('superadmin.impersonation.started', [
            'business_id' => $user->business_id,
            'impersonated_user_id' => $user->id,
        ]);

        return [
            'token' => $token,
            'user' => new UserResource($user->load('roles')),
        ];
    }
}
