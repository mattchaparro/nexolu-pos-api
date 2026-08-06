<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function __construct(private ProductService $productService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $ingredientsEnabled = (bool) $request->user()?->business?->hasFeature('ingredients');

        return ProductResource::collection(
            Product::with('category')
                ->when($ingredientsEnabled, fn ($query) => $query->with('ingredients'))
                ->orderBy('name')
                ->paginate()
        );
    }

    public function store(StoreProductRequest $request): ProductResource
    {
        $product = $this->productService->create($request->user()->business, $request->validated());

        return new ProductResource($product);
    }

    public function show(Request $request, Product $product): ProductResource
    {
        $ingredientsEnabled = (bool) $request->user()?->business?->hasFeature('ingredients');

        return new ProductResource($product->load(['category', ...($ingredientsEnabled ? ['ingredients'] : [])]));
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product = $this->productService->update($request->user()->business, $product, $request->validated());

        return new ProductResource($product);
    }

    public function destroy(Product $product): Response
    {
        $product->delete();

        return response()->noContent();
    }
}
