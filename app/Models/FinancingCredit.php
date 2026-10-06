<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que una financiadora le debe al negocio por una venta: se crea al vender
 * (SaleService) y se marca pagado cuando llega el giro, completo y uno por
 * credito.
 */
#[Fillable([
    'business_id',
    'sale_id',
    'financing_provider_id',
    'amount',
    'approval_number',
    'customer_name',
    'customer_phone',
    'customer_identification',
    'status',
    'expected_payout_date',
    'paid_at',
    'payout_method',
    'payout_reference',
    'received_by_user_id',
    'notes',
])]
class FinancingCredit extends Model
{
    use BelongsToBranch, BelongsToBusiness;

    /** Medio de pago reservado de la linea financiada: no es plata en caja. */
    public const PAYMENT_METHOD = 'financiado';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    /** La deuda es de la financiadora con el negocio, no con una sede. */
    public static function scopesByBranch(): bool
    {
        return false;
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expected_payout_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(FinancingProvider::class, 'financing_provider_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->whereNotNull('expected_payout_date')
            ->whereDate('expected_payout_date', '<', now()->toDateString());
    }
}
