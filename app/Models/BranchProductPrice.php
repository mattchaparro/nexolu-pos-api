<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Precio de un producto o variante en UNA sede, cuando se aparta del
 * catalogo. Ver la migracion create_branch_product_prices_table.
 *
 * La fila solo existe cuando hay diferencia: no hay una fila por sede y
 * producto, y su ausencia significa "el precio del catalogo".
 */
#[Fillable(['business_id', 'branch_id', 'product_id', 'product_variant_id', 'price'])]
class BranchProductPrice extends Model
{
    use BelongsToBusiness;

    protected function casts(): array
    {
        return [
            'price' => 'float',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
