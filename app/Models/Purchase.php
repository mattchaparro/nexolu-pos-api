<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Purchase extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'supplier_id',
        'purchased_at',
        'invoice_number',
        'notes',
        'user_id',
        'payment_status',
        'paid_at',
    ];

    protected $appends = ['total', 'paid', 'balance'];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }

    public function reminders(): MorphMany
    {
        return $this->morphMany(Reminder::class, 'remindable');
    }

    public function getTotalAttribute(): float
    {
        return (float) $this->lines->sum('line_total_cop');
    }

    public function getPaidAttribute(): float
    {
        return (float) $this->payments->sum('amount');
    }

    public function getBalanceAttribute(): float
    {
        // Una compra pagada de contado nunca genera un PurchasePayment (ver
        // PurchaseService::registerPurchase) - getPaidAttribute() se queda en
        // 0 para ese caso, asi que basarse solo en total-paid dejaria
        // balance=total con payment_status=paid, una inconsistencia visible
        // en el frontend (badge "Pagada" junto a un saldo pendiente).
        if ($this->payment_status === 'paid') {
            return 0.0;
        }

        return max(0.0, round($this->total - $this->paid, 2));
    }
}
