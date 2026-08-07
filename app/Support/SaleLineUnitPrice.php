<?php

namespace App\Support;

use App\Models\Product;

/**
 * Precio unitario efectivo de una linea de venta (POS).
 */
final class SaleLineUnitPrice
{
    /**
     * @param  array{unit_price?: float|int|string|null}  $item
     */
    public static function resolve(Product $product, array $item): float
    {
        $sent = isset($item['unit_price']) && $item['unit_price'] !== '' && $item['unit_price'] !== null
            ? (float) $item['unit_price']
            : null;

        if ($product->price_varies_at_sale) {
            return $sent === null ? (float) $product->price : max(0.0, round($sent, 2));
        }

        return (float) $product->price;
    }
}
