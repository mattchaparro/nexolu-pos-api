<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductCategoryRequest;
use App\Http\Requests\Api\V1\UpdateProductCategoryRequest;
use App\Http\Resources\Api\V1\ProductCategoryResource;
use App\Models\ProductCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

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
        // products.category_id no tiene FK en el schema compartido (sin
        // "on delete") y product_categories.parent_id es "on delete set
        // null" - sin este guard, borrar una categoria dejaria productos
        // con un category_id colgando o promoveria sus subcategorias a
        // nivel raiz en silencio, sin que el usuario lo pida.
        if ($productCategory->products()->count() > 0) {
            throw ValidationException::withMessages([
                'category' => 'No se puede eliminar: tiene productos asociados.',
            ]);
        }

        if ($productCategory->children()->count() > 0) {
            throw ValidationException::withMessages([
                'category' => 'No se puede eliminar: tiene subcategorías asociadas.',
            ]);
        }

        $productCategory->delete();

        return response()->noContent();
    }
}
