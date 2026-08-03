<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Not scoped by BelongsToBusiness on purpose: business_id is nullable, and a
 * null row is a global/system default type shared by every business
 * (see DEFAULT_NAMES). Callers must filter business_id = mine OR null.
 */
#[Fillable(['business_id', 'name', 'slug'])]
class ExpenseType extends Model
{
    use HasFactory, SoftDeletes;

    /** Default categories seeded for every business the first time it lists its expenses. */
    const DEFAULT_NAMES = [
        'Insumos',
        'Varios',
        'Salarios',
        'Fijos',
        'Descuentos',
        'Estimado no soportado',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'type_id');
    }
}
