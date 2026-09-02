<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cobro que la pasarela dice haber hecho.
 *
 * Es el extracto del proveedor, no una venta. Se guarda aparte y se cruza
 * despues -- igual que una conciliacion bancaria: la fuente del banco y la
 * propia se comparan, nunca se fusionan. Fusionarlas seria dejar que el
 * proveedor cree ventas en el POS.
 *
 * Llegan por webhook desde el Payments Core (`merchant_payment.received`),
 * incluidos los que el POS nunca origino: el QR fisico del datafono, donde
 * el comprador paga sin que nadie toque la caja.
 */
#[Fillable([
    'business_id', 'provider_slug', 'provider_payment_id', 'amount',
    'payment_method', 'approval_number', 'occurred_at', 'sale_id',
    'matched_at', 'payload',
])]
class GatewayPayment extends Model
{
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
            'matched_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /** La venta con la que cuadro. Nulo = el POS no tiene ese cobro. */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function isMatched(): bool
    {
        return $this->sale_id !== null;
    }
}
