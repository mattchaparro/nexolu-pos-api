<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Services\PrintAgentService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al Nexolu Print Agent por su token (Bearer) en las rutas
 * /print-agent/*.
 *
 * No es Sanctum a proposito (ver PrintAgent): el token del agente no abre
 * ninguna ruta del POS, y un token de empleado no abre estas. Fija el
 * negocio del agente como tenant para que BelongsToBusiness acote cada
 * consulta, igual que hace la tienda online con sus visitantes.
 *
 * Si el negocio apago la impresion, responde 403 y el agente se queda
 * quieto en vez de seguir sacando tickets.
 */
class AuthenticatePrintAgent
{
    public const ATTRIBUTE = 'printAgent';

    public function __construct(private PrintAgentService $agents) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $agent = $token !== '' ? $this->agents->findByToken($token) : null;
        if ($agent === null) {
            return response()->json(['message' => 'Equipo no vinculado. Vuelve a vincularlo con un código nuevo.'], 401);
        }

        $business = Business::withoutGlobalScopes()->find($agent->business_id);
        if ($business === null || ! $business->hasFeature('auto_printing')) {
            return response()->json(['message' => 'La impresión automática está apagada para este negocio.'], 403);
        }

        TenantContext::set($business);
        $request->attributes->set(self::ATTRIBUTE, $agent);

        return $next($request);
    }
}
