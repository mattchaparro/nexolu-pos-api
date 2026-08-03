<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abono a una cuenta abierta (se paga de a poco antes de cerrarla). Al cerrar,
 * estos abonos se convierten en el reparto por medio de pago de la venta.
 */
#[Fillable(['sale_id', 'amount', 'payment_method', 'payer_label', 'user_id'])]
class SalePartialPayment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
