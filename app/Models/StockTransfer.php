<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Traslado de inventario entre dos sedes del mismo negocio. Ver la migracion
 * create_stock_transfers_tables para por que es una entidad propia y no dos
 * movimientos sueltos.
 *
 * No usa BelongsToBranch: un traslado no pertenece a una sede, toca dos. Se
 * consulta por from_branch_id/to_branch_id explicitamente (ver
 * scopeInvolvingBranch), o un empleado de la sede 1 no veria los traslados
 * que le mandaron desde la 2.
 */
#[Fillable([
    'business_id',
    'from_branch_id',
    'to_branch_id',
    'user_id',
    'status',
    'reference',
    'notes',
    'transferred_at',
])]
class StockTransfer extends Model
{
    use BelongsToBusiness;

    public const STATUS_COMPLETED = 'completed';

    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Traslados donde esta sede es origen o destino. */
    public function scopeInvolvingBranch($query, int $branchId)
    {
        return $query->where(fn ($inner) => $inner
            ->where('from_branch_id', $branchId)
            ->orWhere('to_branch_id', $branchId)
        );
    }
}
