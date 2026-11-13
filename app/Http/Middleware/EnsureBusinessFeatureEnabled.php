<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessFeatureEnabled
{
    /**
     * `feature:a,b` deja pasar si el negocio tiene CUALQUIERA de los dos: la
     * tienda online y el menú digital comparten pantallas (pedidos, ajustes,
     * fotos de producto) aunque sean módulos distintos.
     */
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $user = $request->user();

        if (! $user || ! $user->business_id) {
            return $next($request);
        }

        $business = $user->business;
        if ($business && ! collect($features)->contains(fn (string $feature) => $business->hasFeature($feature))) {
            abort(403, 'Este modulo no esta habilitado para este negocio.');
        }

        return $next($request);
    }
}
