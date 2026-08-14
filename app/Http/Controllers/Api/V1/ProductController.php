<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\ProductService;
use App\Support\AuditLogger;
use App\Support\ProductAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

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
            ->get(['id', 'stock', 'is_single_sale', 'is_active', 'low_stock_alert_threshold']);

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
            'inactive_count' => $products->where('is_active', false)->count(),
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

    /**
     * Catálogo completo de productos vendibles, para Vender - distinto de
     * index() (paginado en 200, pensado para Catálogo/Compras/edición
     * masiva). Ver App\Support\ProductAvailability::forBusiness() para el
     * porqué (bug real: con más de 200 productos, los que empiezan por
     * "Z" nunca llegaban a Vender) y el cache de 10 min.
     */
    public function sellable(Request $request): AnonymousResourceCollection
    {
        return ProductResource::collection(
            ProductAvailability::forBusiness($request->user()->business)
        );
    }

    /** @var list<string> */
    private const STOCK_FILTERS = ['out_of_stock', 'low_stock', 'inactive', 'single_sale', 'recipe'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $business = $request->user()?->business;
        $ingredientsEnabled = (bool) $business?->hasFeature('ingredients');

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

        // category_id: igual que Admin\InventoryController del legacy,
        // filtrar por una categoria raiz incluye tambien sus subcategorias.
        if ($request->filled('category_id')) {
            $query->whereIn('category_id', ProductCategory::idsIncludingChildren((int) $request->integer('category_id')));
        }

        // for_layaway: puerto de LayawaysController::create()/show() del
        // legacy (mismo filtro en los dos metodos alla) - un apartado nunca
        // vende servicios ni productos inactivos/sin stock, y el negocio
        // puede restringir a categorias especificas via
        // layaway_allowed_category_ids. include_ids evita que un producto
        // que ya esta en el apartado (pero ahora sin stock, p.ej.) desaparezca
        // del selector al editar - mismo whereIn($currentProductIds) que
        // legacy hace en show().
        if ($request->boolean('for_layaway')) {
            $query->where('is_service', false)->where('is_active', true);

            $includeIds = collect(Arr::wrap($request->input('include_ids', [])))
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->all();

            $query->where(function ($q) use ($includeIds) {
                $q->where('track_stock', false)->orWhere('stock', '>', 0);
                if ($includeIds !== []) {
                    $q->orWhereIn('id', $includeIds);
                }
            });

            $allowedCategoryIds = $business?->layaway_allowed_category_ids;
            if (is_array($allowedCategoryIds) && $allowedCategoryIds !== []) {
                $query->whereIn('category_id', $allowedCategoryIds);
            }
        }

        // filter: a diferencia de category_id/search (puerto directo de
        // legacy), esto es nuevo - legacy solo muestra estos estados como
        // cards de resumen de solo lectura (ver summary()), nunca como
        // filtros reales del listado.
        if ($request->filled('filter') && in_array($request->input('filter'), self::STOCK_FILTERS, true)) {
            $lowStockThreshold = (float) ($business?->low_stock_alert_threshold ?? 5);

            match ($request->input('filter')) {
                'out_of_stock' => $query->where('stock', '<=', 0),
                'low_stock' => $query->whereRaw('stock <= COALESCE(low_stock_alert_threshold, ?)', [$lowStockThreshold]),
                'inactive' => $query->where('is_active', false),
                'single_sale' => $query->where('is_single_sale', true),
                'recipe' => $query->whereHas('ingredients'),
                default => null,
            };
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

        AuditLogger::log('product.created', ['product_id' => $product->id, 'name' => $product->name, 'price' => $product->price]);

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

        AuditLogger::log('product.updated', ['product_id' => $product->id, 'name' => $product->name, 'price' => $product->price]);

        return new ProductResource($product);
    }

    public function destroy(Product $product): Response
    {
        AuditLogger::log('product.deleted', ['product_id' => $product->id, 'name' => $product->name]);

        $product->delete();

        return response()->noContent();
    }

    public function duplicate(Request $request, Product $product): ProductResource
    {
        $copy = $this->productService->duplicate($request->user()->business, $product);

        AuditLogger::log('product.duplicated', ['original_id' => $product->id, 'copy_id' => $copy->id, 'name' => $copy->name]);

        return new ProductResource($copy);
    }
}
