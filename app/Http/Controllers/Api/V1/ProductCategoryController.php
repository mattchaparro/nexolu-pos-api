<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductCategoryRequest;
use App\Http\Requests\Api\V1\UpdateProductCategoryRequest;
use App\Http\Resources\Api\V1\ProductCategoryResource;
use App\Models\ProductCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProductCategoryResource::collection(
            ProductCategory::orderBy('name')->get()
        );
    }

    public function store(StoreProductCategoryRequest $request): ProductCategoryResource
    {
        $category = ProductCategory::create($request->validated());

        // refresh(): "icon" tiene DEFAULT 'inventory_2' en BD; si el request no
        // lo manda, queda null en memoria hasta releer la fila.
        return new ProductCategoryResource($category->refresh());
    }

    public function show(ProductCategory $productCategory): ProductCategoryResource
    {
        return new ProductCategoryResource($productCategory);
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $productCategory): ProductCategoryResource
    {
        $productCategory->update($request->validated());

        return new ProductCategoryResource($productCategory->fresh());
    }

    public function destroy(ProductCategory $productCategory): Response
    {
        $productCategory->delete();

        return response()->noContent();
    }
}
