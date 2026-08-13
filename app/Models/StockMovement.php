<?php

namespace App\Models;

use App\Support\ProductAvailability;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToBusiness, HasFactory;

    const TYPE_ENTRY = 'entry';

    const TYPE_EXIT = 'exit';

    const TYPE_ADJUSTMENT = 'adjustment';

    const TYPE_SALE = 'sale';

    const TYPES = [self::TYPE_ENTRY, self::TYPE_EXIT, self::TYPE_ADJUSTMENT, self::TYPE_SALE];

    protected $fillable = [
        'product_id',
        'ingredient_id',
        'business_id',
        'type',
        'stock_movement_reason_id',
        'purchase_line_id',
        'quantity',
        'unit_cost_cop',
        'reference',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_cost_cop' => 'float',
        ];
    }

    /**
     * El stock del producto/ingrediente SIEMPRE se mueve a través de un
     * StockMovement, nunca con un update directo a products.stock/
     * ingredients.stock: así queda auditoria completa de cada entrada/salida
     * (quién, cuándo, por qué) en vez del machetazo legacy de mutar la
     * columna sin dejar rastro. product_id e ingredient_id son mutuamente
     * excluyentes (igual que en el schema compartido).
     */
    protected static function booted(): void
    {
        static::created(function (StockMovement $movement) {
            if ($movement->product_id) {
                self::applyToProduct($movement);

                return;
            }

            if ($movement->ingredient_id) {
                Ingredient::withoutGlobalScopes()->whereKey($movement->ingredient_id)->increment('stock', $movement->quantity);
            }
        });
    }

    private static function applyToProduct(StockMovement $movement): void
    {
        $movement->product()->withoutGlobalScopes()->increment('stock', $movement->quantity);

        // El stock cambió por SQL directo (increment), que no dispara el
        // evento saved de Product - sin esto, el catálogo cacheado de
        // Vender (ver App\Support\ProductAvailability::forBusiness())
        // mostraría el stock viejo hasta 10 min.
        if ($movement->business_id) {
            ProductAvailability::clearCache((int) $movement->business_id);
        }

        $product = Product::withoutGlobalScopes()->find($movement->product_id);
        if (! $product || ! $product->is_single_sale || ! $product->track_stock) {
            return;
        }

        $product->refresh();

        if ((int) $product->stock <= 0 && $product->is_active) {
            $product->forceFill(['is_active' => false])->save();
        } elseif ((int) $product->stock > 0 && ! $product->is_active) {
            $product->forceFill(['is_active' => true])->save();
        }
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(StockMovementReason::class, 'stock_movement_reason_id');
    }

    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
