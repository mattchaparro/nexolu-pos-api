<?php

namespace App\Support;

use App\Models\AiChannelIdentity;
use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resuelve a quien le llegan las notificaciones proactivas de WhatsApp de un
 * negocio: los administradores que ya vincularon su numero.
 *
 * Se acota a admins porque las preferencias de notificaciones las activa el
 * dueno para si mismo desde Settings, no para toda la nomina. Un empleado
 * puede tener el chat vinculado sin por eso recibir las alertas del negocio.
 */
class WhatsAppRecipients
{
    /** @return Collection<int, AiChannelIdentity> */
    public static function linkedAdmins(Business $business): Collection
    {
        return AiChannelIdentity::query()
            ->where('business_id', $business->id)
            ->where('channel', AiChannelIdentity::CHANNEL_WHATSAPP)
            ->whereNotNull('verified_at')
            ->whereHas('user', fn (Builder $q) => $q->whereHas(
                'roles',
                fn (Builder $r) => $r->where('name', 'admin')
            ))
            ->get();
    }
}
