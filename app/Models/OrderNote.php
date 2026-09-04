<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una nota sobre un pedido. Ver la migracion para por que las internas y las
 * del comprador viven juntas.
 */
#[Fillable(['order_id', 'business_id', 'user_id', 'visibility', 'body', 'channels', 'delivery'])]
class OrderNote extends Model
{
    use BelongsToBusiness;

    /** Solo la ve el equipo del negocio. */
    public const VISIBILITY_INTERNAL = 'internal';

    /** Se le mando al comprador por algun canal. */
    public const VISIBILITY_CUSTOMER = 'customer';

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'delivery' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Si algun canal entrego. Un "enviado" a medias -- correo si, WhatsApp no
     * -- sigue siendo enviado, pero la pantalla muestra cual fallo.
     */
    public function reachedSomeone(): bool
    {
        foreach ($this->delivery ?? [] as $result) {
            if (($result['status'] ?? null) === 'sent') {
                return true;
            }
        }

        return false;
    }
}
