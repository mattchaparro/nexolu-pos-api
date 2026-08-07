<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashClosing extends Model
{
    use BelongsToBusiness, HasFactory;

    /** Cierre hecho por una persona desde la pantalla de cierre de caja. */
    const CREATED_VIA_MANUAL = 'manual';

    protected $fillable = [
        'business_id',
        'date',
        'total_sales',
        'total_cash',
        'total_other_methods',
        'payment_breakdown',
        'opening_cash',
        'total_expenses',
        'expected_cash',
        'actual_cash',
        'base_for_next_day',
        'difference',
        'closed_by',
        'created_via',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_sales' => 'decimal:2',
            'total_cash' => 'decimal:2',
            'total_other_methods' => 'decimal:2',
            'payment_breakdown' => 'array',
            'opening_cash' => 'decimal:2',
            'total_expenses' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'base_for_next_day' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(CashShift::class, 'closed_by_cash_closing_id');
    }
}
