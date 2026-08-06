<?php

namespace App\Support;

use App\Exceptions\AiChatBlockedException;
use App\Models\User;

/**
 * Arma el TenantContext que el Nexolu IA Core espera en cada llamada (chat o
 * completions): lo que Laravel ya sabe del usuario autenticado, para que el
 * Core confie en la afirmacion sin tener sesion propia. Centralizado para que
 * AiChatController y AiInsightController no dupliquen el mismo armado.
 *
 * Tambien es el unico choke point de los 5 puntos de entrada al Asistente de
 * IA (chat web, insights, drafts, WhatsApp) - por eso el bloqueo del
 * superadmin (Business::ai_chat_blocked, ver
 * SuperAdmin\BusinessesController::toggleAiChatBlock()) se revisa aqui y no
 * en cada controller/job por separado.
 */
class AiTenantContext
{
    /**
     * @return array<string, mixed>
     *
     * @throws AiChatBlockedException
     */
    public static function forUser(User $user, string $channel = 'web'): array
    {
        if ($user->business->ai_chat_blocked) {
            throw new AiChatBlockedException;
        }

        return [
            'business_id' => (string) $user->business_id,
            'user_id' => (string) $user->id,
            'is_admin' => $user->hasRole('admin'),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'features' => $user->business->enabledFeatureNames(),
            'channel' => $channel,
            'timezone' => 'America/Bogota',
            'locale' => 'es',
        ];
    }
}
