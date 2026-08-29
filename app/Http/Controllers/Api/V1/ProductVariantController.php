<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

/**
 * Acciones puntuales sobre UNA variante, sin pasar por el payload completo
 * del producto.
 *
 * Las variantes se crean y se editan anidadas en POST/PUT /products (ver
 * ValidatesProductVariants), y eso esta bien para el formulario; pero
 * pausar una talla desde el listado del catalogo por esa via obligaria al
 * frontend a reenviar TODAS las variantes del producto, y omitir una sola
 * por error la borraria (ProductService::syncVariants soft-deletea las que
 * no vienen en el payload). De ahi este endpoint acotado.
 */
class ProductVariantController extends Controller
{
    public function toggle(Request $request, Product $product, ProductVariant $variant): ProductVariantResource
    {
        abort_unless($variant->product_id === $product->id, 404);

        $variant->update(['is_active' => ! $variant->is_active]);

        return new ProductVariantResource($variant->load('attributeValues.productAttribute'));
    }
}
