<?php

namespace App\Http\Resources\Api\V1\Storefront;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Un producto tal y como lo ve un COMPRADOR ANONIMO en internet.
 *
 * Deliberadamente separado de ProductResource y no una variacion suya: aquel
 * expone `cost_price`, el stock exacto y banderas internas del POS
 * (is_single_sale, price_varies_at_sale, can_manage_stock...) que no tienen
 * por que salir del negocio. Reutilizarlo con un `when()` habria dejado el
 * catalogo publico a una condicion mal escrita de distancia de filtrar el
 * margen del comerciante.
 *
 * @mixin Product
 */
class StorefrontProductResource extends JsonResource
{
    /**
     * Tope de unidades que se publican. El carrito necesita un maximo para no
     * dejar pedir mas de lo que hay, pero el numero exacto de inventario es
     * informacion del negocio (y de su competencia): se recorta.
     */
    public const MAX_VISIBLE_STOCK = 10;

    public static function visibleStock(float $stock): int
    {
        return (int) min(max($stock, 0), self::MAX_VISIBLE_STOCK);
    }

    /**
     * Unidades ya comprometidas por pedidos pendientes, para descontarlas de
     * lo que se publica. Sin esto la tienda seguiria ofreciendo lo que otro
     * comprador ya aparto (ver OrderService::reservedUnits).
     *
     * Es estatico y no una propiedad del Resource porque una coleccion crea
     * una instancia por producto y la reserva se consulta UNA vez para toda
     * la pagina: pasarla por el constructor obligaria a mapear a mano.
     *
     * @var array{products: array<int, int>, variants: array<int, int>}
     */
    private static array $reserved = ['products' => [], 'variants' => []];

    /** @param  array{products: array<int, int>, variants: array<int, int>}  $reserved */
    public static function useReservations(array $reserved): void
    {
        self::$reserved = $reserved;
    }

    public static function reservedForProduct(int $productId): int
    {
        return self::$reserved['products'][$productId] ?? 0;
    }

    public static function reservedForVariant(int $variantId): int
    {
        return self::$reserved['variants'][$variantId] ?? 0;
    }

    /**
     * Promedio y conteo de reseñas aprobadas, por el mismo motivo que las
     * reservas: se calcula UNA vez para toda la pagina (ver
     * ProductReviewService::summaryFor) en lugar de una consulta por card.
     *
     * @var array<int, array{average: float, count: int}>
     */
    private static array $ratings = [];

    /** @param  array<int, array{average: float, count: int}>  $ratings */
    public static function useRatings(array $ratings): void
    {
        self::$ratings = $ratings;
    }

    /**
     * "Va bien con". Solo se llena en la ficha individual: en el listado del
     * catalogo seria una consulta por producto para algo que nadie mira ahi.
     *
     * @var Collection<int, Product>|null
     */
    private static $crossSells = null;

    /**
     * `null` vuelve al estado "no aplica". Hay que llamarlo asi en el
     * LISTADO: el estatico sobrevive a toda la peticion (y en los tests, a
     * todo el proceso), de modo que sin esto una ficha vista antes dejaria
     * sus sugerencias pegadas a la siguiente respuesta.
     */
    public static function useCrossSells(?Collection $products): void
    {
        self::$crossSells = $products;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hasVariants = $this->relationLoaded('variants') && $this->variants->isNotEmpty();

        return [
            'id' => $this->id,
            'name' => $this->name,
            // La descripcion larga es la de la ficha publica; `description` es
            // la nota corta que ve el cajero y puede tener jerga interna.
            'description' => $this->online_description ?: $this->description,
            // Modo de empleo. Lo escribe el comerciante en el POS y ya existia
            // en el catalogo interno; en la tienda es contenido de venta, asi
            // que va aparte de la descripcion y no concatenado a ella.
            'how_to_use' => $this->how_to_use,
            'price' => (float) ($hasVariants ? $this->variants->min('price') : $this->price),
            'has_variants' => $hasVariants,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'url' => $image->url(),
                'thumbnail_url' => $image->thumbnailUrl(),
                'alt' => $image->alt,
                'variant_id' => $image->product_variant_id,
            ])->values()),
            'image' => $this->image,
            'variants' => StorefrontVariantResource::collection($this->whenLoaded('variants')),
            'available' => $this->storefrontAvailability($hasVariants),
            // Siempre presente, con count 0 cuando no hay reseñas: asi la
            // tienda no tiene que distinguir "sin reseñas" de "no vino el
            // dato".
            'rating' => self::$ratings[$this->id] ?? ['average' => 0.0, 'count' => 0],
            // Ausente en el listado, presente (aunque vacio) en la ficha: asi
            // la tienda distingue "no aplica" de "no hay sugerencias".
            'cross_sells' => self::$crossSells === null
                ? null
                : self::$crossSells->map(fn (Product $related) => [
                    'id' => $related->id,
                    'name' => $related->name,
                    'price' => (float) ($related->variants->isNotEmpty()
                        ? $related->variants->min('price')
                        : $related->price),
                    'has_variants' => $related->variants->isNotEmpty(),
                    'image' => $related->images->first()?->thumbnailUrl() ?? $related->image,
                ])->values(),
        ];
    }

    /**
     * @return array{in_stock: bool, quantity: ?int}
     */
    private function storefrontAvailability(bool $hasVariants): array
    {
        if (! $this->track_stock) {
            return ['in_stock' => true, 'quantity' => null];
        }

        $stock = $hasVariants
            ? (float) $this->variants->where('is_active', true)
                ->sum(fn ($variant) => max(0, $variant->stock - self::reservedForVariant($variant->id)))
            : (float) $this->stock - self::reservedForProduct($this->id);

        return [
            'in_stock' => $stock > 0,
            'quantity' => self::visibleStock($stock),
        ];
    }
}
