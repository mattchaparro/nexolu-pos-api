<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al superadmin del monolito legacy (pos-saas) contra los
 * endpoints administrativos server-to-server (ej. correr los parches de
 * post-migracion de un negocio) - una API key fija de aplicacion, no un
 * token de usuario Sanctum, mismo criterio que EnsureValidIaCoreKey para
 * el IA Core. No hay usuario humano en esta llamada: pos-saas la dispara
 * desde su propio panel de superadmin, ya autenticado ahi con su propio
 * rol superadmin.
 */
class EnsureValidLegacyAdminKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.legacy.admin_key');
        $provided = $request->bearerToken();

        if (! $expected || ! $provided || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'No autorizado.'], 401);
        }

        return $next($request);
    }
}
