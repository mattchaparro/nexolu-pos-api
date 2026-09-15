<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un carrito entrante de WhatsApp (webhook `order` de Meta) y en que quedo:
 * la venta abierta que creo solo, o el motivo por el que espera revision.
 * Ver App\Jobs\ProcessWhatsAppOrder y la migracion de whatsapp_orders para
 * el porque de una tabla propia en vez de columnas en `sales`.
 */
#[Fillable([
    'business_id',
    'wamid',
    'phone',
    'customer_name',
    'raw_order',
    'status',
    'sale_id',
    'error',
])]
class WhatsappOrder extends Model
{
    use BelongsToBusiness;

    public const STATUS_CREATED = 'created';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'raw_order' => 'array',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
