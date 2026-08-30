<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Storefront\StorefrontCategoryResource;
use App\Http\Resources\Api\V1\Storefront\StorefrontProductResource;
use App\Http\Resources\Api\V1\Storefront\StorefrontSettingsResource;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\OrderService;
use App\Services\ProductReviewService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Catalogo publico de la tienda online. **Sin autenticacion**: quien llega
 * aca es un comprador anonimo desde internet.
 *
 * El aislamiento no depende de que estas consultas se acuerden de filtrar por
 * negocio: App\Http\Middleware\ResolveStorefrontTenant resuelve el negocio
 * del slug y lo deja en el TenantContext, y a partir de ahi el global scope
 * de BelongsToBusiness filtra igual que lo haria con un usuario logueado (ver
 * su docblock). Los `where` de aca son de VISIBILIDAD (publicado, activo), no
 * de tenant.
 */
class StorefrontCatalogController extends Controller
{
    public function __construct(
        private OrderService $orders,
        private ProductReviewService $reviews,
    ) {}

    public function settings(Request $request): StorefrontSettingsResource
    {
        return new StorefrontSettingsResource($request->attributes->get('storeSettings'));
    }

    /**
     * Solo categorias publicadas Y con algo que mostrar: una categoria vacia
     * en la navegacion de una tienda es un callejon sin salida.
     *
     * "Con algo que mostrar" incluye lo que cuelga de sus HIJAS. Una categoria
     * padre normalmente no tiene productos propios -- "Bebidas" existe para
     * agrupar "Sodas" y "Jugos" --, y exigirle productos directos la dejaba
     * fuera de la respuesta: sus subcategorias llegaban con un `parent_id`
     * que apuntaba a nada y el arbol de navegacion se rompia justo en el caso
     * normal.
     */
    public function categories(): AnonymousResourceCollection
    {
        $categories = ProductCategory::where('is_published', true)
            ->where(function (Builder $query) {
                $query->whereHas('products', fn (Builder $q) => $this->visible($q))
                    ->orWhereHas('children', fn (Builder $q) => $q
                        ->where('is_published', true)
                        ->whereHas('products', fn (Builder $p) => $this->visible($p)));
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return StorefrontCategoryResource::collection($categories);
    }

    public function products(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->tap(fn (Builder $q) => $this->visible($q))
            ->with(['category', 'images', 'variants' => fn ($q) => $q->where('is_active', true)])
            ->with('variants.attributeValues.productAttribute');

        if ($request->filled('search')) {
            // Solo por nombre: el sku es un codigo interno y buscar por el
            // dejaria adivinar el catalogo completo a fuerza de prefijos.
            $query->where('name', 'like', '%'.trim((string) $request->input('search')).'%');
        }

        if ($request->filled('category_id')) {
            // `idsIncludingChildren` es ESTATICO y recibe el id. Se llamaba
            // sobre la instancia y sin argumentos, asi que filtrar por
            // cualquier categoria existente reventaba con un 500. El `find`
            // se conserva para no confiar en un id de otro negocio: el global
            // scope lo resuelve, y si no existe el filtro no devuelve nada.
            $category = ProductCategory::find($request->integer('category_id'));
            $query->whereIn(
                'category_id',
                $category ? ProductCategory::idsIncludingChildren($category->id) : [-1],
            );
        }

        $this->sort($query, (string) $request->input('sort', 'name'));

        $paginated = $query->paginate(24)->withQueryString();
        $business = TenantContext::current();
        $ids = $paginated->pluck('id')->all();

        StorefrontProductResource::useReservations($this->orders->reservedUnits((int) $business?->id, $ids));
        if ($business !== null) {
            StorefrontProductResource::useRatings($this->reviews->summaryFor($business, $ids));
        }

        return StorefrontProductResource::collection($paginated);
    }

    /**
     * Catalogo cerrado de ordenamientos. Nunca se interpola lo que llega del
     * cliente en el SQL: un `sort` desconocido cae en el orden por defecto.
     *
     * Ordenar por precio NO usa `products.price`: un producto con variantes
     * publica el minimo de sus variantes activas (ver
     * StorefrontProductResource), asi que ordenar por la columna del producto
     * daria una lista ordenada por un precio que el comprador no ve. La
     * subconsulta reproduce exactamente el precio publicado.
     */
    private function sort(Builder $query, string $sort): void
    {
        $precioPublicado = <<<'SQL'
            COALESCE(
                (SELECT MIN(pv.price) FROM product_variants pv
                  WHERE pv.product_id = products.id AND pv.is_active = 1),
                products.price
            )
        SQL;

        match ($sort) {
            'price_asc' => $query->orderByRaw("{$precioPublicado} ASC"),
            'price_desc' => $query->orderByRaw("{$precioPublicado} DESC"),
            // "Novedades" ordena por alta en el POS. Lo correcto seria la
            // fecha en que se PUBLICO en la tienda, pero `is_published` es un
            // booleano sin marca de tiempo: un articulo viejo que el
            // comerciante acaba de publicar sale al fondo. Agregar un
            // `published_at` implica otro ALTER sobre `products`, que el
            // monolito comparte, y no se justifica por un criterio de orden.
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->orderBy('name'),
        };
    }

    /**
     * El id se lee de la ruta y no se recibe como argumento a proposito.
     * Laravel resuelve los parametros ESCALARES de un metodo de controlador
     * por POSICION, no por nombre (el nombre solo cuenta para el binding de
     * modelos): como esta ruta tiene dos parametros y `{business}` va
     * primero, un `string $productId` recibia el slug del negocio en vez del
     * id, `(int) 'mi-tienda'` daba 0 y TODA ficha de producto respondia 404.
     */
    public function product(Request $request): StorefrontProductResource
    {
        $product = Product::query()
            ->tap(fn (Builder $q) => $this->visible($q))
            ->with(['category', 'images'])
            ->with(['variants' => fn ($q) => $q->where('is_active', true)->with('attributeValues.productAttribute')])
            ->find((int) $request->route('productId'));

        abort_if($product === null, 404);

        $business = TenantContext::current();
        StorefrontProductResource::useReservations($this->orders->reservedUnits((int) $business?->id, [$product->id]));
        if ($business !== null) {
            StorefrontProductResource::useRatings($this->reviews->summaryFor($business, [$product->id]));
        }

        return new StorefrontProductResource($product);
    }

    /**
     * Que un producto sea visible en la tienda. Publicar es un acto explicito
     * del comerciante (is_published arranca en false), y ademas tiene que
     * estar activo en el POS: pausarlo desde el catalogo tiene que sacarlo de
     * internet en el acto, sin un segundo interruptor que recordar.
     */
    private function visible(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->where('is_active', true)
            ->where('is_service', false);
    }

    /** El negocio resuelto por el middleware, por si hiciera falta. */
    protected function business(): ?Business
    {
        return TenantContext::current();
    }
}
