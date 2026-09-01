<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Dos responsabilidades relacionadas, igual que en legacy (misma clase
 * allá también):
 *
 * 1. effectiveStock(): stock realmente vendible de UN producto - para uno
 *    con receta (ingredientes) y el feature activo, products.stock es una
 *    columna "fantasma" que nunca se decrementa; la disponibilidad real
 *    sale del insumo más escaso de la receta.
 *
 * 2. forBusiness(): el catálogo COMPLETO de productos vendibles de un
 *    negocio, para Vender - a diferencia de ProductController::index()
 *    (paginado, para Catálogo/Compras/edición masiva), Vender filtra/busca
 *    en el cliente sin ida y vuelta por cada tecla, así que necesita todo
 *    el catálogo activo de una sola vez, no una página de 200 (con más de
 *    200 productos, los que caen después en el orden alfabético - ej. los
 *    que empiezan por "Z" - nunca llegaban al navegador). Cache de 10 min
 *    por negocio, salteado si el negocio tiene el feature 'ingredients'
 *    (el stock efectivo cambia con cada movimiento de ingrediente, cachear
 *    ahí dejaría cifras viejas). Invalidado desde Product::booted()
 *    (guardar/eliminar un producto) y desde StockMovement::applyToProduct()
 *    (el incremento SQL directo del stock no dispara el evento saved de
 *    Product).
 */
class ProductAvailability
{
    public static function effectiveStock(Product $product, bool $ingredientsEnabled, bool $variantsEnabled = false, ?int $branchId = null): float
    {
        if (! $product->track_stock) {
            return INF;
        }

        // Con una sede activa esto responde "cuanto hay EN ESTA SEDE"; sin
        // ella (consolidado, alertas por correo, comandos) responde el total
        // del negocio. Ver App\Traits\HasBranchStock::stockAt() - es lo que
        // permite que ninguno de los ~10 sitios que llaman aca haya tenido
        // que cambiar para volverse multisede.
        $branchId ??= BranchContext::branchId();

        // Un producto con variantes no llega nunca a tener receta a la vez
        // (mutuamente excluyentes, ver ProductService::extractVariants()),
        // asi que esta rama y la de receta de abajo nunca compiten entre si
        // para el mismo producto.
        if ($variantsEnabled && $product->hasVariants()) {
            return (float) $product->variants
                ->where('is_active', true)
                ->sum(fn (ProductVariant $variant) => $variant->stockAt($branchId));
        }

        $effectiveStock = $product->stockAt($branchId);

        if ($ingredientsEnabled && $product->ingredients->isNotEmpty()) {
            $possibleUnits = self::calculateRecipeUnits($product, $branchId);
            if (is_finite($possibleUnits)) {
                $effectiveStock = max(0.0, $possibleUnits);
            }
        }

        return $effectiveStock;
    }

    /**
     * Stock de UNA variante puntual - usado al validar/descontar una linea
     * de venta que ya trae product_variant_id resuelto, a diferencia de
     * effectiveStock() (que agrega TODAS las variantes activas de un
     * producto, para el catalogo/las tarjetas de Vender).
     */
    public static function effectiveVariantStock(ProductVariant $variant, ?int $branchId = null): float
    {
        return $variant->stockAt($branchId);
    }

    private static function calculateRecipeUnits(Product $product, ?int $branchId = null): float
    {
        return (float) $product->ingredients
            ->map(function ($ingredient) use ($branchId) {
                $required = (float) ($ingredient->pivot->quantity ?? 0);
                if ($required <= 0) {
                    return INF;
                }

                // El insumo tambien se cuenta por sede: una receta se puede
                // preparar en la sede que tiene los ingredientes, no en la
                // que solo los tiene "en el total del negocio".
                return floor($ingredient->stockAt($branchId) / $required);
            })
            ->min();
    }

    /**
     * La clave lleva la sede (cada local ve su propio stock) y una version
     * por negocio. La version existe porque invalidar "el catalogo de este
     * negocio" tiene que alcanzar a TODAS sus sedes de una vez - editar un
     * producto o cambiarle el precio afecta a todas - y ningun driver de
     * cache permite borrar por comodin.
     */
    public static function cacheKey(int $businessId, ?int $branchId = null): string
    {
        $branchId ??= BranchContext::branchId();

        return sprintf('pos_products_%d_%d_v%d', $businessId, $branchId ?? 0, self::cacheVersion($businessId));
    }

    public static function clearCache(int $businessId): void
    {
        Cache::forever(self::versionKey($businessId), self::cacheVersion($businessId) + 1);
    }

    private static function versionKey(int $businessId): string
    {
        return "pos_products_version_{$businessId}";
    }

    private static function cacheVersion(int $businessId): int
    {
        return (int) Cache::get(self::versionKey($businessId), 1);
    }

    /** @return Collection<int, Product> */
    public static function forBusiness(Business $business): Collection
    {
        $ingredientsEnabled = (bool) $business->hasFeature('ingredients');
        $variantsEnabled = (bool) $business->hasFeature('variants');

        // El stock de una variante cambia con cada venta, igual que el de
        // receta - cachear aca dejaria cifras viejas hasta 10 min.
        if (! $ingredientsEnabled && ! $variantsEnabled) {
            // Cache::remember() no puede guardar modelos Eloquent directamente:
            // config/cache.php fija serializable_classes=false (todo el resto
            // del codigo ya cachea solo escalares/arrays, ver StockMovementReason
            // y ProcessWhatsAppInbound) para que un valor de cache corrupto o
            // manipulado nunca pueda deserializar en un objeto PHP arbitrario.
            // Con esa bandera, unserialize() convierte cualquier objeto en
            // __PHP_Incomplete_Class - un modelo cacheado crudo se rompe en la
            // SIGUIENTE lectura (bug real, no hipotetico: reproducible con
            // Cache::put()+Cache::get() en el mismo proceso). Se cachean los
            // atributos crudos (array) y se rehidratan con newFromBuilder(),
            // igual que hace Eloquent internamente al leer de la BD - sin
            // volver a golpear la base en cada hit de cache.
            $rows = Cache::remember(
                self::cacheKey($business->id),
                now()->addMinutes(10),
                fn () => self::toCacheableArray(self::fetchSellableProducts($business, $ingredientsEnabled, $variantsEnabled))
            );

            return self::fromCacheableArray($rows);
        }

        return self::fetchSellableProducts($business, $ingredientsEnabled, $variantsEnabled);
    }

    /** @return Collection<int, Product> */
    private static function fetchSellableProducts(Business $business, bool $ingredientsEnabled, bool $variantsEnabled = false): Collection
    {
        $branchId = BranchContext::branchId();

        return Product::where('business_id', $business->id)
            ->where('is_active', true)
            ->with('category')
            ->withBranchStock($branchId)
            // El saldo por sede de insumos y variantes se precarga junto con
            // el del producto: sin esto, effectiveStock() consultaria una vez
            // por cada fila del catalogo.
            ->when($ingredientsEnabled, fn ($q) => $q->with(['ingredients' => fn ($r) => $r->withBranchStock($branchId)]))
            ->when($variantsEnabled, fn ($q) => $q->with([
                'variants.attributeValues.productAttribute',
                'variants' => fn ($r) => $r->withBranchStock($branchId),
            ]))
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array{attributes: array<string, mixed>, category: array<string, mixed>|null}>
     */
    private static function toCacheableArray(Collection $products): array
    {
        return $products->map(fn (Product $product) => [
            'attributes' => $product->getAttributes(),
            'category' => $product->relationLoaded('category') && $product->category
                ? $product->category->getAttributes()
                : null,
        ])->all();
    }

    /**
     * @param  list<array{attributes: array<string, mixed>, category: array<string, mixed>|null}>  $rows
     * @return Collection<int, Product>
     */
    private static function fromCacheableArray(array $rows): Collection
    {
        $productPrototype = new Product;
        $categoryPrototype = new ProductCategory;

        return Collection::make($rows)->map(function (array $row) use ($productPrototype, $categoryPrototype) {
            $product = $productPrototype->newFromBuilder($row['attributes']);
            $product->setRelation(
                'category',
                $row['category'] ? $categoryPrototype->newFromBuilder($row['category']) : null
            );

            return $product;
        });
    }
}
