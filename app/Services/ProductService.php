<?php

namespace App\Services;

use App\Models\Product;

/**
 * Unico punto de creacion/edicion de productos. Extraido de ProductController
 * para que App\Capabilities\Products\CreateProductCapability (invocada por
 * el Nexolu IA Core) reutilice exactamente la misma logica que el endpoint
 * HTTP normal, en vez de reimplementarla.
 */
class ProductService
{
    /** @param  array<string, mixed>  $data  ya validado, con category_id resuelto */
    public function create(array $data): Product
    {
        $product = Product::create([
            'stock' => 0,
            'cost_price' => 0,
            ...$data,
        ]);

        // refresh(): columnas con DEFAULT a nivel de BD que el request no mando
        // (is_active, track_stock, is_single_sale, ...) quedan null en la
        // instancia en memoria hasta releerla - create() no las repuebla solo.
        return $product->refresh()->load('category');
    }

    /** @param  array<string, mixed>  $data */
    public function update(Product $product, array $data): Product
    {
        $product->update($data);

        return $product->fresh()->load('category');
    }
}
