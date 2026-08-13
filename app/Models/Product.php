<?php

namespace App\Models;

use App\Support\ProductAvailability;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

        // Invalida el catalogo cacheado de Vender (ver
        // App\Support\ProductAvailability::forBusiness()) - un producto
        // nuevo, editado o borrado no debe tardar hasta 10 min en
        // aparecer/desaparecer/actualizar precio ahi.
        $clearCache = function (Product $product) {
            if ($product->business_id) {
                ProductAvailability::clearCache((int) $product->business_id);
            }
        };
        static::saved($clearCache);
        static::deleted($clearCache);
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

    /**
     * Σ(stock × precio) de productos con control de inventario - usado por
     * el resumen del Catalogo para el card "Valor inventario" (solo se
     * muestra si el negocio no tiene la feature "ingredients": con receta
     * activa, el valor de venta de un producto ya no depende de su propio
     * stock, ver isStockManagedByIngredientsRecipe()).
     */
    public static function sumInventoryRetailValueCop(): float
    {
        return (float) static::query()
            ->where('is_service', false)
            ->where('track_stock', true)
            ->toBase()
            ->selectRaw('COALESCE(SUM(stock * price), 0) as inventory_value')
            ->value('inventory_value');
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

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class)->withPivot('quantity')->withTimestamps();
    }

    /**
     * Un producto con receta descuenta el stock de sus ingredientes en vez
     * de mover products.stock directamente (ver
     * App\Support\ProductAvailability::effectiveStock() para el calculo de
     * disponibilidad y App\Services\StockService para el descuento).
     */
    public function isStockManagedByIngredientsRecipe(): bool
    {
        if ($this->is_service || ! $this->track_stock) {
            return false;
        }

        $business = $this->relationLoaded('business') ? $this->business : Business::find($this->business_id);

        if (! $business?->hasFeature('ingredients')) {
            return false;
        }

        return $this->relationLoaded('ingredients') ? $this->ingredients->isNotEmpty() : $this->ingredients()->exists();
    }

    /**
     * Costo calculado como Σ(ingredient.cost_price × pivot.quantity). Null
     * si el producto no tiene receta.
     */
    public function recipeCost(): ?float
    {
        $ingredients = $this->relationLoaded('ingredients') ? $this->ingredients : $this->ingredients()->get();

        if ($ingredients->isEmpty()) {
            return null;
        }

        return round(
            $ingredients->sum(fn (Ingredient $ingredient) => (float) $ingredient->cost_price * (float) $ingredient->pivot->quantity),
            2
        );
    }

    /**
     * Recalcula cost_price desde la receta y lo persiste, si tiene receta.
     */
    public function syncRecipeCost(): void
    {
        $this->load('ingredients');
        $cost = $this->recipeCost();

        if ($cost !== null) {
            $this->update(['cost_price' => $cost]);
        }
    }
}
