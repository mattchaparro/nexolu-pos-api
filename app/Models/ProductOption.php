<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una opción elegible (ej. «BBQ»), gratis o con recargo, que puede descontar un insumo. */
#[Fillable([
    'business_id', 'product_option_group_id', 'name', 'extra_price',
    'ingredient_id', 'ingredient_quantity', 'is_active', 'sort_order',
])]
class ProductOption extends Model
{
    use BelongsToBusiness;

    protected function casts(): array
    {
        return [
            'extra_price' => 'decimal:2',
            'ingredient_quantity' => 'decimal:3',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroup::class, 'product_option_group_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
