<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sale_id',
    'product_id',
    'quantity',
    'unit_price',
    'unit_cost_at_sale',
    'subtotal',
    'discount_id',
    'discount_amount',
    'kitchen_status',
    'kitchen_updated_at',
])]
class SaleItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'unit_cost_at_sale' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'kitchen_updated_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }
}
