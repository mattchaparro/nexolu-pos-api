<?php

namespace App\Support;

use App\Models\LogAction;

/**
 * Rastro de acciones administrativas consultable con SQL (tabla `log_actions`,
 * ya existe en el schema compartido). Solo se llama desde acciones de
 * SuperAdmin (mueven plata, cambian acceso, o son un usuario viendo como
 * otro) - no es un logger general para el resto de la app; retro-instrumentar
 * cada modulo del POS con esto es un cambio aparte, mucho mas grande.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function log(string $action, array $details = []): void
    {
        $request = request();
        $user = $request?->user();

        LogAction::create([
            'action' => $action,
            'url' => $request?->fullUrl(),
            'method' => $request?->method(),
            'ip' => $request?->ip(),
            'agent' => $request?->userAgent(),
            'user_id' => $user?->id,
            'business_id' => $details['business_id'] ?? $user?->business_id,
            'details' => $details,
        ]);
    }
}
