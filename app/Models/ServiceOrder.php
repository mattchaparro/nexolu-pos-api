<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceOrder extends Model
{
    use BelongsToBusiness, HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'client_id',
        'appointment_id',
        'product_id',
        'user_id',
        'service_name',
        'total',
        'amount_paid',
        'notes',
        'status',
        'paid_at',
    ];

    public static array $statuses = [
        'pending' => ['label' => 'Pendiente', 'color' => 'yellow'],
        'partial' => ['label' => 'Abonado', 'color' => 'blue'],
        'paid' => ['label' => 'Pagado', 'color' => 'green'],
        'cancelled' => ['label' => 'Cancelado', 'color' => 'red'],
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function getBalanceAttribute(): float
    {
        return max(0, (float) $this->total - (float) $this->amount_paid);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::$statuses[$this->status]['label'] ?? $this->status;
    }

    /**
     * pending/partial/paid segun cuanto se ha abonado. No toca 'cancelled' -
     * esa transicion solo la hace ServiceOrderService::cancel().
     */
    public function recalculateStatus(): void
    {
        if ($this->status === 'cancelled') {
            return;
        }

        $paid = (float) $this->amount_paid;
        $total = (float) $this->total;

        if ($paid <= 0) {
            $this->status = 'pending';
            $this->paid_at = null;
        } elseif ($paid < $total) {
            $this->status = 'partial';
            $this->paid_at = null;
        } else {
            $this->status = 'paid';
            $this->paid_at = $this->paid_at ?? now();
        }

        $this->save();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceOrderItem::class)->orderBy('sort_order');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ServicePayment::class)->orderBy('created_at');
    }
}
