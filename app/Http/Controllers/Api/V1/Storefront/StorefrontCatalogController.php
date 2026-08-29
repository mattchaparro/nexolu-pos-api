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
    public function __construct(private OrderService $orders) {}

    public function settings(Request $request): StorefrontSettingsResource
    {
        return new StorefrontSettingsResource($request->attributes->get('storeSettings'));
    }

    /**
     * Solo categorias publicadas Y con algo que mostrar: una categoria vacia
     * en la navegacion de una tienda es un callejon sin salida.
     */
    public function categories(): AnonymousResourceCollection
    {
        $categories = ProductCategory::where('is_published', true)
            ->whereHas('products', fn (Builder $query) => $this->visible($query))
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
            $category = ProductCategory::find($request->integer('category_id'));
            $query->whereIn('category_id', $category ? $category->idsIncludingChildren() : [-1]);
        }

        $paginated = $query->orderBy('name')->paginate(24)->withQueryString();
        StorefrontProductResource::useReservations(
            $this->orders->reservedUnits((int) TenantContext::current()?->id, $paginated->pluck('id')->all())
        );

        return StorefrontProductResource::collection($paginated);
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

        StorefrontProductResource::useReservations(
            $this->orders->reservedUnits((int) TenantContext::current()?->id, [$product->id])
        );

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
