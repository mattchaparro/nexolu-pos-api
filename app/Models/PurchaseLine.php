<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Solo lineas de producto por ahora - el legacy tambien admite ingredient_id
 * (inventario por receta), pero ese modulo (Ingredient, ingredient_product,
 * Product::ingredients()) todavia no existe en esta API. La columna
 * ingredient_id sigue existiendo en la tabla compartida pero esta API nunca
 * la usa.
 */
class PurchaseLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'product_id',
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

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'purchase_line_id');
    }
}
