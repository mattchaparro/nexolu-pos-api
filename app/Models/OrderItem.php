<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linea de un pedido. Guarda el nombre y el precio COPIADOS del momento de
 * la compra: el comerciante puede subir el precio o renombrar el producto
 * mientras el pedido esta en curso, y el pedido tiene que poder leerse tal y
 * como se hizo.
 */
#[Fillable([
    'order_id', 'business_id', 'product_id', 'product_variant_id',
    'product_name', 'variant_label', 'quantity', 'unit_price', 'subtotal',
])]
class OrderItem extends Model
{
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
