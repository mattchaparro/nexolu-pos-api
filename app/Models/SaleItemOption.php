<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto de una opción elegida en una línea de venta (nombres y precio copiados). */
#[Fillable([
    'sale_item_id', 'product_option_id', 'group_name', 'name',
    'extra_price', 'ingredient_id', 'ingredient_quantity',
])]
class SaleItemOption extends Model
{
    protected function casts(): array
    {
        return [
            'extra_price' => 'decimal:2',
            'ingredient_quantity' => 'decimal:3',
        ];
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
