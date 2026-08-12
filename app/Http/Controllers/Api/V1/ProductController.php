<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function __construct(private ProductService $productService) {}

    /**
     * Cards de resumen del Catalogo (Inventario bajo, Sin stock, Venta
     * única, Con receta, Valor inventario) - sobre el conjunto completo de
     * productos no-servicio, no solo la pagina visible del listado
     * paginado. Puerto de Admin\InventoryController::buildIndexProps() del
     * legacy.
     */
    public function summary(Request $request): JsonResponse
    {
        $business = $request->user()?->business;
        $ingredientsEnabled = (bool) $business?->hasFeature('ingredients');
        $lowStockThreshold = (float) ($business?->low_stock_alert_threshold ?? 5);

        $products = Product::where('is_service', false)
            ->when($ingredientsEnabled, fn ($q) => $q->with('ingredients:id'))
            ->get(['id', 'stock', 'is_single_sale', 'low_stock_alert_threshold']);

        $lowStockCount = $products->filter(function (Product $p) use ($lowStockThreshold) {
            $threshold = $p->low_stock_alert_threshold !== null ? (float) $p->low_stock_alert_threshold : $lowStockThreshold;

            return (float) $p->stock <= $threshold;
        })->count();

        $withRecipeCount = $ingredientsEnabled
            ? $products->filter(fn (Product $p) => $p->ingredients->count() > 0)->count()
            : 0;

        $showInventoryValueCard = ! $ingredientsEnabled;

        return response()->json([
            'low_stock_count' => $lowStockCount,
            'out_of_stock_count' => $products->filter(fn (Product $p) => (float) $p->stock <= 0)->count(),
            'single_sale_count' => $products->where('is_single_sale', true)->count(),
            'with_recipe_count' => $withRecipeCount,
            'show_inventory_value_card' => $showInventoryValueCard,
            'inventory_value_cop' => $showInventoryValueCard ? round(Product::sumInventoryRetailValueCop()) : null,
        ]);
    }

    /**
     * Cards de resumen de la pestaña Servicios (productos con
     * is_service=true): total, precio variable vs. fijo - equivalente de
     * summary() para el otro catalogo, sin las metricas de stock que no
     * aplican a un servicio. Puerto de las props extra que
     * ProductsController::servicesIndex() del legacy le pasa a Index.vue.
     */
    public function servicesSummary(Request $request): JsonResponse
    {
        $services = Product::where('is_service', true)->get(['id', 'price_varies_at_sale']);

        return response()->json([
            'total_count' => $services->count(),
            'variable_price_count' => $services->where('price_varies_at_sale', true)->count(),
            'fixed_price_count' => $services->where('price_varies_at_sale', false)->count(),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $ingredientsEnabled = (bool) $request->user()?->business?->hasFeature('ingredients');

        $query = Product::with('category')
            ->when($ingredientsEnabled, fn ($q) => $q->with('ingredients'))
            ->orderBy('name');

        // Sin el parametro, se listan ambos (lo necesita Vender, que vende
        // productos y servicios desde la misma grilla) - Catalogo, Compras y
        // edicion masiva pasan is_service explicito para separar uno del
        // otro, igual que Admin\InventoryController/PurchasesController/
        // StockController del legacy.
        if ($request->has('is_service')) {
            $query->where('is_service', $request->boolean('is_service'));
        }

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
