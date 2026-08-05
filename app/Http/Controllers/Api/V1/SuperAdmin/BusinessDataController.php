<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperAdmin\StoreBusinessCategoryRequest;
use App\Http\Requests\Api\V1\SuperAdmin\StoreBusinessProductRequest;
use App\Http\Requests\Api\V1\SuperAdmin\UpdateBusinessProductRequest;
use App\Http\Resources\Api\V1\ProductCategoryResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Ver/editar los datos operativos de UN negocio desde el panel de superadmin
 * (soporte: el negocio pide ayuda cargando su catalogo, o hay que corregir
 * algo a mano). Distinto del CRUD normal de products/categories: ese vive
 * scopeado al negocio autenticado, este cruza cualquier tenant a proposito.
 */
class BusinessDataController extends Controller
{
    public function products(Business $business): AnonymousResourceCollection
    {
        $products = Product::where('business_id', $business->id)
            ->with('category')
            ->orderBy('name')
            ->get();

        return ProductResource::collection($products);
    }

    public function storeProduct(StoreBusinessProductRequest $request, Business $business): ProductResource
    {
        $product = Product::create([
            ...$request->validated(),
            'business_id' => $business->id,
            'cost_price' => 0,
            'stock' => $request->validated('stock') ?? 0,
        ]);

        return new ProductResource($product->load('category'));
    }

    public function updateProduct(UpdateBusinessProductRequest $request, Business $business, Product $product): ProductResource
    {
        abort_unless((int) $product->business_id === (int) $business->id, 404);

        $product->update($request->validated());

        return new ProductResource($product->fresh()->load('category'));
    }

    public function destroyProduct(Business $business, Product $product): Response
    {
        abort_unless((int) $product->business_id === (int) $business->id, 404);

        $product->delete();

        return response()->noContent();
    }

    public function categories(Business $business): AnonymousResourceCollection
    {
        $categories = ProductCategory::where('business_id', $business->id)
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return ProductCategoryResource::collection($categories);
    }

    public function storeCategory(StoreBusinessCategoryRequest $request, Business $business): ProductCategoryResource
    {
        $category = ProductCategory::create([
            ...$request->validated(),
            'business_id' => $business->id,
        ]);

        return new ProductCategoryResource($category);
    }

    public function destroyCategory(Business $business, ProductCategory $category): Response
    {
        abort_unless((int) $category->business_id === (int) $business->id, 404);

        if ($category->products()->count() > 0) {
            throw ValidationException::withMessages(['category' => 'No se puede eliminar: tiene productos.']);
        }

        $category->delete();

        return response()->noContent();
    }
}
