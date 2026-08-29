<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReorderProductImagesRequest;
use App\Http\Requests\Api\V1\StoreProductImageRequest;
use App\Http\Requests\Api\V1\UpdateProductImageRequest;
use App\Http\Resources\Api\V1\ProductImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\ProductImageService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Fotos de un producto. Anidado bajo /products/{product} porque una foto no
 * existe por si sola, y el producto padre es lo que decide de que negocio es
 * (el global scope de BelongsToBusiness ya resuelve el 404 si es de otro).
 *
 * Las variantes se editan anidadas en el payload de products, pero las fotos
 * no: son archivos, viajan como multipart y se suben de a una mientras el
 * comerciante llena el formulario, antes incluso de guardarlo.
 */
class ProductImageController extends Controller
{
    public function __construct(private ProductImageService $images) {}

    public function index(Product $product): AnonymousResourceCollection
    {
        return ProductImageResource::collection($product->images);
    }

    public function store(StoreProductImageRequest $request, Product $product): ProductImageResource
    {
        $variant = null;
        if ($variantId = $request->validated('product_variant_id')) {
            $variant = ProductVariant::where('product_id', $product->id)->findOrFail($variantId);
        }

        $image = $this->images->store(
            $product,
            $request->file('image'),
            $variant,
            $request->validated('alt'),
        );

        return new ProductImageResource($image);
    }

    /**
     * Reasigna una foto a otra variante (o al producto, con null) y edita su
     * texto alternativo. Es la contraparte de subirla: al crear un producto
     * las variantes todavia no tienen id, asi que el destino real de una foto
     * solo se puede corregir despues.
     */
    public function update(UpdateProductImageRequest $request, Product $product, ProductImage $image): ProductImageResource
    {
        abort_unless($image->product_id === $product->id, 404);

        $attributes = $request->validated();

        if (array_key_exists('product_variant_id', $attributes) && $attributes['product_variant_id'] !== null) {
            ProductVariant::where('product_id', $product->id)->findOrFail($attributes['product_variant_id']);
        }

        $image->update($attributes);

        return new ProductImageResource($image);
    }

    public function destroy(Product $product, ProductImage $image): Response
    {
        abort_unless($image->product_id === $product->id, 404);

        $this->images->delete($image);

        return response()->noContent();
    }

    public function reorder(ReorderProductImagesRequest $request, Product $product): AnonymousResourceCollection
    {
        $this->images->reorder($product, $request->validated('image_ids'));

        return ProductImageResource::collection($product->images()->get());
    }
}
