<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCrossSell;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Que sugerir cuando alguien lleva un producto.
 *
 * Las reglas viven aca y no en el controlador porque hay DOS consumidores con
 * necesidades distintas -- el mostrador y la tienda publica -- y lo que se
 * puede sugerir no es lo mismo en cada uno: el cajero puede vender algo que
 * no esta publicado en internet, y la tienda no.
 */
class CrossSellService
{
    /** Mas de esto no es una sugerencia, es otro catalogo. */
    public const MAX_PER_PRODUCT = 6;

    /**
     * Reemplaza las sugerencias de un producto por la lista dada, en ese
     * orden.
     *
     * Es un `sync` y no un alta/baja incremental porque la UI es una lista
     * ordenada: mandar el estado final completo evita que el cliente tenga
     * que calcular diferencias y que dos pestañas se pisen a medias.
     *
     * @param  list<int>  $relatedIds
     *
     * @throws ValidationException
     */
    public function sync(Product $product, array $relatedIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $relatedIds)));

        if (count($ids) > self::MAX_PER_PRODUCT) {
            throw ValidationException::withMessages([
                'cross_sell_ids' => 'Puedes sugerir hasta '.self::MAX_PER_PRODUCT.' productos.',
            ]);
        }

        // Sugerirse a si mismo no significa nada y ademas duplicaria el
        // articulo en el carrito del comprador.
        if (in_array($product->id, $ids, true)) {
            throw ValidationException::withMessages([
                'cross_sell_ids' => 'Un producto no puede sugerirse a sí mismo.',
            ]);
        }

        // Que TODOS sean del mismo negocio. El global scope ya filtra la
        // consulta, asi que un id de otro comercio simplemente no aparece y
        // la cuenta no cuadra: se rechaza en vez de guardar a medias.
        $propios = Product::whereIn('id', $ids)->pluck('id')->all();

        if (count($propios) !== count($ids)) {
            throw ValidationException::withMessages([
                'cross_sell_ids' => 'Alguno de los productos elegidos ya no existe.',
            ]);
        }

        DB::transaction(function () use ($product, $ids) {
            $product->crossSells()->delete();

            foreach ($ids as $position => $relatedId) {
                $product->crossSells()->create([
                    'business_id' => $product->business_id,
                    'related_product_id' => $relatedId,
                    'sort_order' => $position,
                ]);
            }
        });
    }

    /**
     * Sugerencias para VARIOS productos a la vez, sin repetir y sin incluir
     * los que ya estan en la lista.
     *
     * Es lo que necesita el carrito: pedir la ficha de cada articulo seria
     * una peticion por linea, y ademas sugeriria cosas que el comprador ya
     * lleva -- que se lee como que la tienda no se entera de lo que hay en
     * el carrito.
     *
     * @param  list<int>  $productIds
     * @return Collection<int, Product>
     */
    public function forProducts(array $productIds, int $limit = 4, bool $publicOnly = true)
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));

        if ($ids === []) {
            return Product::whereRaw('1 = 0')->get();
        }

        $sugeridos = ProductCrossSell::whereIn('product_id', $ids)
            ->orderBy('sort_order')
            ->pluck('related_product_id')
            ->reject(fn ($id) => in_array((int) $id, $ids, true))
            ->unique()
            ->take($limit)
            ->values();

        if ($sugeridos->isEmpty()) {
            return Product::whereRaw('1 = 0')->get();
        }

        $query = Product::whereIn('id', $sugeridos)->where('is_active', true);

        if ($publicOnly) {
            $query->where('is_published', true)->where('is_service', false);
        }

        return $query->get()->sortBy(fn (Product $p) => $sugeridos->search($p->id))->values();
    }

    /**
     * Las sugerencias de un producto, ya resueltas a productos y en orden.
     *
     * `$publicOnly` es la diferencia entre los dos consumidores: el cajero
     * puede vender algo que no esta publicado en internet, la tienda no.
     *
     * @return Collection<int, Product>
     */
    public function forProduct(Product $product, bool $publicOnly = false)
    {
        $ids = $product->crossSells()->orderBy('sort_order')->pluck('related_product_id');

        if ($ids->isEmpty()) {
            return Product::whereRaw('1 = 0')->get();
        }

        $query = Product::whereIn('id', $ids)->where('is_active', true);

        if ($publicOnly) {
            $query->where('is_published', true)->where('is_service', false);
        }

        // El orden lo manda el comerciante, no la base: se reordena en PHP
        // porque `whereIn` no preserva el orden de la lista.
        return $query->get()->sortBy(fn (Product $p) => $ids->search($p->id))->values();
    }
}
