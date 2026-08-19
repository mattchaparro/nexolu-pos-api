<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use App\Traits\NormalizesPaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * Una venta puede ser directa (mostrador: nace y se cierra en el mismo
 * instante, status='closed') o una cuenta abierta (status='open': mesa/tab que
 * se cobra despues, con abonos parciales y posible pago dividido al cerrar).
 * kitchen_status es el rollup de los kitchen_status de sus items, calculado
 * por App\Services\KitchenBoardService::syncSaleStatus() - no se edita
 * directo.
 */
#[Fillable([
    'business_id',
    'user_id',
    'payment_method',
    'total',
    'status',
    'table_id',
    'customer_name',
    'customer_phone',
    'customer_identification',
    'client_id',
    'is_delivery',
    'delivery_fee',
    'is_non_revenue',
    'non_revenue_reason',
    'is_credit',
    'kitchen_status',
    'kitchen_updated_at',
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
            'kitchen_updated_at' => 'datetime',
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

    public function table(): BelongsTo
    {
        return $this->belongsTo(BusinessTable::class, 'table_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Abonos registrados antes del cierre (cuenta abierta). */
    public function partialPayments(): HasMany
    {
        return $this->hasMany(SalePartialPayment::class)->orderBy('id');
    }

    /** Reparto por medio de pago cuando la cuenta se cobra con varios medios a la vez. */
    public function paymentSplits(): HasMany
    {
        return $this->hasMany(SalePaymentSplit::class);
    }

    public function receivable(): HasOne
    {
        return $this->hasOne(Receivable::class);
    }

    /**
     * Ingreso de esta venta repartido por medio de pago: si hubo pago
     * dividido, el reparto real (paymentSplits); si no, todo el total al
     * unico payment_method - salvo fiado, que no es ingreso todavia (se
     * cuenta cuando el Receivable se cobra, no aqui). Fuente unica para
     * cualquier calculo de efectivo esperado (cierre de caja, turnos).
     *
     * @return Collection<string, float>
     */
    public function allocatedRevenueByPaymentMethod(): Collection
    {
        $this->loadMissing('paymentSplits');

        if ($this->paymentSplits->isNotEmpty()) {
            return $this->paymentSplits
                ->groupBy('payment_method')
                ->map(fn ($group) => round((float) $group->sum('amount'), 2));
        }

        if ($this->payment_method && ! $this->is_credit) {
            return collect([$this->payment_method => round((float) $this->total, 2)]);
        }

        return collect();
    }
}
