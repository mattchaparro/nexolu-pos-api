<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una sugerencia: "a quien lleva X, ofrecele Y".
 *
 * Dirigida (ver la migracion): la fila X->Y no implica Y->X.
 */
#[Fillable(['business_id', 'product_id', 'related_product_id', 'sort_order'])]
class ProductCrossSell extends Model
{
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }
}
