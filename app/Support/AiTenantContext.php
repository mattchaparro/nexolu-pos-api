<?php

namespace App\Support;

use App\Models\User;

/**
 * Arma el TenantContext que el Nexolu IA Core espera en cada llamada (chat o
 * completions): lo que Laravel ya sabe del usuario autenticado, para que el
 * Core confie en la afirmacion sin tener sesion propia. Centralizado para que
 * AiChatController y AiInsightController no dupliquen el mismo armado.
 */
class AiTenantContext
{
    /** @return array<string, mixed> */
    public static function forUser(User $user): array
    {
        return [
            'business_id' => (string) $user->business_id,
            'user_id' => (string) $user->id,
            'is_admin' => $user->hasRole('admin'),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'features' => $user->business->enabledFeatureNames(),
            'channel' => 'web',
            'timezone' => 'America/Bogota',
            'locale' => 'es',
        ];
    }
}
