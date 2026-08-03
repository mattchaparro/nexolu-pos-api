<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use App\Traits\NormalizesPaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Solo cubre el flujo de venta directa (mostrador): nace y se cierra en el
 * mismo instante. El flujo de cuentas abiertas (status=open, table_id,
 * partialPayments, pagos mixtos via SalePaymentSplit, kitchen board) es un
 * modulo aparte que todavia no existe en esta API.
 */
#[Fillable([
    'business_id',
    'user_id',
    'payment_method',
    'total',
    'status',
    'customer_name',
    'customer_phone',
    'customer_identification',
    'is_delivery',
    'delivery_fee',
    'is_non_revenue',
    'non_revenue_reason',
    'is_credit',
    'invoice_number',
    'cart_discount_id',
    'cart_discount_amount',
    'service_charge_amount',
    'ipoconsumo_amount',
    'closed_at',
    'closed_by_user_id',
])]
class Sale extends Model
{
    use BelongsToBusiness, HasFactory, NormalizesPaymentMethod;

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'is_delivery' => 'boolean',
            'delivery_fee' => 'decimal:2',
            'is_non_revenue' => 'boolean',
            'is_credit' => 'boolean',
            'cart_discount_amount' => 'decimal:2',
            'service_charge_amount' => 'decimal:2',
            'ipoconsumo_amount' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Sale $sale) {
            if ($sale->status !== 'open' && $sale->closed_at === null) {
                $sale->closed_at = now();
            }
            if ($sale->status !== 'open' && $sale->closed_by_user_id === null) {
                $sale->closed_by_user_id = auth()->id();
            }
        });
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function cartDiscount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'cart_discount_id');
    }
}
