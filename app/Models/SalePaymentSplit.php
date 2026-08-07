<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reparto del cobro de una venta por medio de pago, cuando se paga con varios
 * medios a la vez (efectivo + transferencia, division de cuenta entre personas).
 * Es la unica fuente correcta del desglose por metodo cuando payment_method='mixed'.
 */
#[Fillable(['sale_id', 'payment_method', 'amount', 'payer_label'])]
class SalePaymentSplit extends Model
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
}
