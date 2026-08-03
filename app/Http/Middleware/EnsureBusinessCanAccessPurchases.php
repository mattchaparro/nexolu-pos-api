<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessCanAccessPurchases
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->business_id) {
            return $next($request);
        }

        $business = $user->business;
        if ($business && ! $business->canAccessPurchases()) {
            abort(403, 'Este modulo no esta habilitado para este negocio.');
        }

        return $next($request);
    }
}
