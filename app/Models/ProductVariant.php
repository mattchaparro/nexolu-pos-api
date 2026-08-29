<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una combinacion de valores de atributo activada manualmente por el
 * comerciante para un Product (ver Product::variants()/hasVariants()) -
 * precio, costo, stock y sku son propios de la variante, no heredados del
 * producto padre. products.stock/price siguen siendo columnas "fantasma"
 * para un producto con variantes, igual concepto que ya existe para un
 * producto con receta (ver ProductAvailability).
 */
#[Fillable(['product_id', 'business_id', 'sku', 'price', 'cost_price', 'stock', 'low_stock_alert_threshold', 'is_active'])]
class ProductVariant extends Model
{
    use BelongsToBusiness, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttributeValue::class, 'product_variant_attribute_value')
            ->withPivot('product_attribute_id')
            ->withTimestamps();
    }
}
