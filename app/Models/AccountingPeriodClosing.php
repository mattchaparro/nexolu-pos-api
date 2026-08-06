<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cierre de un mes contable de un negocio (ver App\Services\ManagerialAccountingService).
 * `summary` congela el resultado de monthlyReport() al momento del cierre -
 * si un dato de origen cambia despues (p.ej. una venta se anula), el cierre
 * ya guardado no se recalcula solo.
 */
#[Fillable([
    'business_id',
    'year',
    'month',
    'status',
    'summary',
    'notes',
    'closed_by_user_id',
    'closed_at',
])]
class AccountingPeriodClosing extends Model
{
    use BelongsToBusiness, HasFactory;

    const STATUS_CLOSED = 'closed';

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
