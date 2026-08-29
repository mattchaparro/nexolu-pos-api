<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitacora de estados de un pedido. `user_id` nulo = lo movio el sistema
 * (creacion, expiracion automatica).
 */
#[Fillable(['order_id', 'from_status', 'to_status', 'user_id', 'note'])]
class OrderStatusHistory extends Model
{
    protected $table = 'order_status_history';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
