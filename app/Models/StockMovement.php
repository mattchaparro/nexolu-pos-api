<?php

namespace App\Models;

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
        'business_id',
        'type',
        'stock_movement_reason_id',
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
     * El stock del producto SIEMPRE se mueve a través de un StockMovement, nunca
     * con un update directo a products.stock: así queda auditoria completa de
     * cada entrada/salida (quién, cuándo, por qué) en vez del machetazo legacy
     * de mutar la columna sin dejar rastro.
     */
    protected static function booted(): void
    {
        static::created(function (StockMovement $movement) {
            $movement->product()->withoutGlobalScopes()->increment('stock', $movement->quantity);

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
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(StockMovementReason::class, 'stock_movement_reason_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
