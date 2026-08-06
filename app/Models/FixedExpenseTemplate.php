<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plantilla de un gasto fijo recurrente (arriendo, nomina, etc.). El comando
 * expenses:register-scheduled crea un Expense por plantilla activa cuyo
 * day_of_month coincide con la fecha de corrida.
 */
#[Fillable(['business_id', 'name', 'amount', 'expense_type_id', 'active', 'scope', 'day_of_month'])]
class FixedExpenseTemplate extends Model
{
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'active' => 'boolean',
            'day_of_month' => 'integer',
        ];
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(ExpenseType::class, 'expense_type_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
