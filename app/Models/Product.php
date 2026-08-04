<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'name',
    'description',
    'how_to_use',
    'price',
    'stock',
    'low_stock_alert_threshold',
    'track_stock',
    'is_single_sale',
    'is_service',
    'price_varies_at_sale',
    'duration_minutes',
    'sku',
    'cost_price',
    'category_id',
    'image',
    'is_active',
    'business_id',
])]
class Product extends Model
{
    use BelongsToBusiness, HasFactory, SoftDeletes;

    const SKU_PREFIX = 'PROD-';

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'is_active' => 'boolean',
            'track_stock' => 'boolean',
            'is_single_sale' => 'boolean',
            'is_service' => 'boolean',
            'price_varies_at_sale' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (! $product->sku) {
                $product->sku = self::generateSkuForBusiness((int) $product->business_id);
            }
        });
    }

    public static function generateSkuForBusiness(int $businessId): string
    {
        $prefix = self::SKU_PREFIX;
        $prefixLen = strlen($prefix);

        $max = (int) DB::table('products')
            ->where('business_id', $businessId)
            ->where('sku', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(sku, ?) AS UNSIGNED)) as m', [$prefixLen + 1])
            ->value('m');

        $next = max(1, $max + 1);
        $suffix = str_pad((string) $next, 3, '0', STR_PAD_LEFT);

        return $prefix.$suffix;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function costHistory(): HasMany
    {
        return $this->hasMany(ProductCostHistory::class);
    }
}
