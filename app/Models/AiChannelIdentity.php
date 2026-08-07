<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vinculo verificado entre un usuario y su identificador en un canal externo
 * (hoy solo 'whatsapp': el numero, en digitos con codigo de pais, sin '+').
 * Un external_id pertenece a un solo usuario en toda la plataforma; un
 * usuario tiene a lo sumo un vinculo por canal (ver unique keys en schema.sql).
 */
#[Fillable(['business_id', 'user_id', 'channel', 'external_id', 'verified_at'])]
class AiChannelIdentity extends Model
{
    use BelongsToBusiness, HasFactory;

    const CHANNEL_WHATSAPP = 'whatsapp';

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
