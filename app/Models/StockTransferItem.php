<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linea de un traslado. Exactamente una de product_id/product_variant_id/
 * ingredient_id lleva valor, igual que en stock_movements y branch_stocks.
 */
#[Fillable([
    'stock_transfer_id',
    'business_id',
    'product_id',
    'product_variant_id',
    'ingredient_id',
    'quantity',
    'unit_cost_cop',
])]
class StockTransferItem extends Model
{
    use BelongsToBusiness;

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_cost_cop' => 'float',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
