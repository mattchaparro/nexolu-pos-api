<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use App\Traits\HasBranchPrice;
use App\Traits\HasBranchStock;
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
    use BelongsToBusiness, HasBranchPrice, HasBranchStock, HasFactory, SoftDeletes;

    /** Columna de este modelo en branch_stocks (ver HasBranchStock). */
    public const BRANCH_STOCK_COLUMN = 'product_variant_id';

    /** Columna de este modelo en branch_product_prices (ver HasBranchPrice). */
    public const BRANCH_PRICE_COLUMN = 'product_variant_id';

    protected static function booted(): void
    {
        static::creating(function (ProductVariant $variant) {
            if (! $variant->sku) {
                $variant->sku = self::generateSkuForProduct($variant);
            }
        });
    }

    /**
     * SKU derivado del producto padre: PROD-039-1, PROD-039-2, ...
     *
     * Product ya autogeneraba el suyo (ver Product::booted()) pero la
     * variante no, y encima el sku era obligatorio al crearla: un
     * comerciante con talla x color tenia que inventarse un codigo por cada
     * combinacion antes de poder guardar. Se numera en vez de usar los
     * valores del atributo porque el pivot de attribute_value se sincroniza
     * DESPUES de crear la fila - aca todavia no se sabe si es la "S" o la
     * "Roja".
     */
    public static function generateSkuForProduct(ProductVariant $variant): string
    {
        $base = Product::withoutGlobalScopes()->find($variant->product_id)?->sku
            ?: 'VAR-'.$variant->product_id;

        $taken = self::withoutGlobalScopes()
            ->withTrashed()
            ->where('business_id', $variant->business_id)
            ->where('sku', 'like', $base.'-%')
            ->pluck('sku')
            ->all();

        $next = 1;
        while (in_array($base.'-'.$next, $taken, true)) {
            $next++;
        }

        return $base.'-'.$next;
    }

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
