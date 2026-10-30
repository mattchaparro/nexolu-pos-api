<?php

namespace App\Support\Auth;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Cuanto vive un token del POS.
 *
 * Antes vencia a las 4 horas de iniciar sesion, se estuviera usando o no
 * (sanctum.expiration = 240, que Sanctum cuenta desde created_at). Un
 * negocio que abre a las 8 a. m. quedaba afuera a las 12 m., a veces en
 * medio de una venta (Central Cell, oct-2026). Ahora vence por INACTIVIDAD:
 * mientras la app este abierta (consulta el turno cada 30 s y Sanctum
 * actualiza last_used_at en cada peticion) la sesion sigue viva, y solo se
 * cae tras `idle_minutes` sin uso. `max_lifetime_days` pone un tope absoluto
 * para que un token olvidado en una tablet no viva para siempre.
 */
final class TokenLifetime
{
    public static function isAlive(PersonalAccessToken $token): bool
    {
        $idleMinutes = (int) config('sanctum.idle_minutes', 1440);
        $maxDays = (int) config('sanctum.max_lifetime_days', 30);

        $lastActivity = $token->last_used_at ?? $token->created_at;

        if ($lastActivity instanceof Carbon && $idleMinutes > 0 && $lastActivity->lt(now()->subMinutes($idleMinutes))) {
            return false;
        }

        if ($token->created_at instanceof Carbon && $maxDays > 0 && $token->created_at->lt(now()->subDays($maxDays))) {
            return false;
        }

        return true;
    }
}
