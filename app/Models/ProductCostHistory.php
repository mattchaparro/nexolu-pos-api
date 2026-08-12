<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCostHistory extends Model
{
    use BelongsToBusiness, HasFactory;

    // "product_cost_history" ya es el nombre real de la tabla - Eloquent lo
    // pluralizaria a "product_cost_histories" por defecto.
    protected $table = 'product_cost_history';

    const SOURCE_PURCHASE = 'purchase';

    const SOURCE_MANUAL_ADJUSTMENT = 'manual_adjustment';

    protected $fillable = [
        'business_id',
        'product_id',
        'cost_before',
        'cost_after',
        'source',
        'purchase_id',
        'user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'cost_before' => 'decimal:4',
            'cost_after' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
