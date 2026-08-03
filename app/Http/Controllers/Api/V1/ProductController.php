<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProductResource::collection(
            Product::with('category')->orderBy('name')->paginate()
        );
    }

    public function store(StoreProductRequest $request): ProductResource
    {
        $product = Product::create([
            'stock' => 0,
            'cost_price' => 0,
            ...$request->validated(),
        ]);

        // refresh(): columnas con DEFAULT a nivel de BD que el request no mando
        // (is_active, track_stock, is_single_sale, ...) quedan null en la
        // instancia en memoria hasta releerla - create() no las repuebla solo.
        return new ProductResource($product->refresh()->load('category'));
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load('category'));
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return new ProductResource($product->fresh()->load('category'));
    }

    public function destroy(Product $product): Response
    {
        $product->delete();

        return response()->noContent();
    }
}
