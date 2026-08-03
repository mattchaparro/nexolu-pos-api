<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'business_id',
    'date',
    'description',
    'value',
    'scope',
    'payment_method',
    'type_id',
    'linkable_type',
    'linkable_id',
])]
class Expense extends Model
{
    use BelongsToBusiness, HasFactory, SoftDeletes;

    const DEFAULT_PAYMENT_METHODS = ['Efectivo', 'Nequi', 'Daviplata', 'Transferencia', 'Tarjeta'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'value' => 'decimal:2',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ExpenseType::class, 'type_id');
    }

    /**
     * Producto opcional al que se asocia el gasto (reposicion de stock, etc.).
     * El vinculo a Ingredient de la app legacy no esta soportado aun: ese
     * modulo no existe en esta API todavia.
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
