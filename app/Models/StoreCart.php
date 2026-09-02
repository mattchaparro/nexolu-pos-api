<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El carrito de un comprador anonimo, espejado en el servidor.
 *
 * No es la fuente de verdad de la compra: el precio y la disponibilidad se
 * releen contra la base al hacer el pedido (`OrderService::resolveLines`),
 * igual que antes. Esto es una copia para poder recuperar el carrito de
 * quien se fue sin comprar.
 */
#[Fillable([
    'business_id', 'token', 'items', 'subtotal',
    'contact_name', 'contact_phone', 'contact_email',
    'last_activity_at', 'reminded_at', 'order_id',
])]
class StoreCart extends Model
{
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'subtotal' => 'decimal:2',
            'last_activity_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Sin forma de escribirle, no hay nada que recuperar. */
    public function isReachable(): bool
    {
        return ($this->contact_email !== null && $this->contact_email !== '')
            || ($this->contact_phone !== null && $this->contact_phone !== '');
    }

    public function isConverted(): bool
    {
        return $this->order_id !== null;
    }
}
