<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToBusiness;
use App\Traits\NormalizesPaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fiado: saldo pendiente de una venta a credito. Se crea/fusiona automaticamente
 * desde SaleService::syncReceivable() cuando una venta (directa o cuenta
 * abierta al cerrar) tiene is_credit=true - nunca se crea a mano via API.
 */
#[Fillable([
    'business_id',
    'sale_id',
    'customer_name',
    'customer_phone',
    'customer_identification',
    'customer_key',
    'client_id',
    'amount',
    'balance',
    'status',
    'payment_method',
    'paid_at',
    'collected_by_user_id',
    'notes',
])]
class Receivable extends Model
{
    use BelongsToBranch, BelongsToBusiness, HasFactory, NormalizesPaymentMethod;

    /**
     * El fiado se registra donde se origino, pero NO se filtra por sede: la
     * deuda es con el negocio y el cliente tiene que poder abonar en
     * cualquier local. Filtrarlo haria que la caja de enfrente no encuentre
     * la deuda que el cliente viene a pagar.
     */
    public static function scopesByBranch(): bool
    {
        return false;
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function collectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by_user_id');
    }
}
