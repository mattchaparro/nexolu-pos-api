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

        $query = Product::with('category')
            ->when($ingredientsEnabled, fn ($q) => $q->with('ingredients'))
            ->orderBy('name');

        if ($request->filled('search')) {
            $term = '%'.trim((string) $request->input('search')).'%';
            $query->where(function ($sub) use ($term) {
                $sub->where('name', 'like', $term)->orWhere('sku', 'like', $term);
            });
        }

        // El POS (Vender) necesita el catalogo casi completo de una sola vez
        // para filtrar/buscar en el cliente sin ida y vuelta por cada tecla -
        // per_page override acotado a 200 en vez de dejar la paginacion
        // abierta a pedir miles de filas de un tiro.
        $perPage = max(1, min((int) $request->integer('per_page', 15), 200));

        return ProductResource::collection($query->paginate($perPage)->withQueryString());
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
