<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Mesa configurada de un negocio (para restaurantes/cafeterias con cuentas
 * abiertas por mesa).
 */
#[Fillable(['business_id', 'name', 'number', 'is_active'])]
class BusinessTable extends Model
{
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function openSale(): HasOne
    {
        return $this->hasOne(Sale::class, 'table_id')->where('status', 'open');
    }
}
