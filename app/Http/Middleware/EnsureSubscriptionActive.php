<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta el uso del sistema a un negocio vencido despues de los dias de
 * gracia (Business::isLockedOut). Antes de esto un negocio vencido seguia
 * vendiendo indefinidamente sin pagar (Las Banquitas, nov-2026).
 *
 * Deja pasar solo lo necesario para pagar y para que la app sepa que esta
 * bloqueada: sesion, datos del negocio, Suscripcion y los medios de pago.
 * 402 (Payment Required) para que el front lo distinga de un 403 de permisos.
 */
class EnsureSubscriptionActive
{
    /** Rutas que siguen abiertas con el negocio bloqueado (sin el prefijo api.v1.). */
    private const ALLOWED_ROUTES = [
        'logout',
        'me',
        'me.update',
        'me.password.update',
        'support.contact',
        'branches.index',
        'business.show',
        'business.billing-profile.*',
        'subscription.*',
        'payment-methods.index',
        'pse.financial-institutions',
        'payment-sources.*',
        'superadmin.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $allowed = array_map(fn (string $name) => 'api.v1.'.$name, self::ALLOWED_ROUTES);

        if (! $user || $user->hasRole('superadmin') || $request->routeIs(...$allowed)) {
            return $next($request);
        }

        if ($user->business?->isLockedOut()) {
            return response()->json([
                'message' => 'La suscripción del negocio está vencida. Realiza el pago para seguir usando el sistema.',
                'code' => 'subscription_locked',
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        return $next($request);
    }
}
