<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una linea de compra es de producto O de ingrediente, nunca ambos (mismas
 * columnas mutuamente excluyentes que StockMovement) - ver
 * PurchaseService::registerPurchase().
 */
class PurchaseLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'product_id',
        'ingredient_id',
        'quantity',
        'line_total_cop',
        'unit_cost_cop',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'line_total_cop' => 'float',
            'unit_cost_cop' => 'float',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'purchase_line_id');
    }
}
