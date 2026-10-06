<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Financiadora (Addi, Banti, Sistecredito...) que le gira al negocio el saldo de una venta. */
#[Fillable(['business_id', 'name', 'expected_payout_days', 'is_active'])]
class FinancingProvider extends Model
{
    use BelongsToBusiness;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'expected_payout_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function credits(): HasMany
    {
        return $this->hasMany(FinancingCredit::class);
    }
}
