<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Precio unitario efectivo de una linea de venta (POS, cuenta abierta,
 * apartado).
 *
 * Antes la variante se resolvia fuera de aqui - `$variant ? $variant->price :
 * SaleLineUnitPrice::resolve(...)`, repetido en SaleService, OpenTabService y
 * LayawayService (dos veces). Al entrar el precio por sede eso habrian sido
 * cuatro sitios donde acordarse del override, y basta olvidar uno para que el
 * mismo producto se cobre distinto segun por que pantalla se venda.
 */
final class SaleLineUnitPrice
{
    /**
     * @param  array{unit_price?: float|int|string|null}  $item
     */
    public static function resolve(Product $product, array $item, ?ProductVariant $variant = null): float
    {
        // La variante manda: tiene su propio precio de catalogo y su propio
        // override por sede. `price_varies_at_sale` es del producto sin
        // variantes, no se combina con ellas.
        if ($variant) {
            return $variant->priceAt();
        }

        $branchPrice = $product->priceAt();

        if (! $product->price_varies_at_sale) {
            return $branchPrice;
        }

        $sent = isset($item['unit_price']) && $item['unit_price'] !== '' && $item['unit_price'] !== null
            ? (float) $item['unit_price']
            : null;

        return $sent === null ? $branchPrice : max(0.0, round($sent, 2));
    }
}
