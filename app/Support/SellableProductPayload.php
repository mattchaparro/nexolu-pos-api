<?php

namespace App\Support;

use App\Http\Resources\Api\V1\IngredientResource;
use App\Http\Resources\Api\V1\ProductCategoryResource;
use App\Http\Resources\Api\V1\ProductImageResource;
use App\Http\Resources\Api\V1\ProductOptionGroupResource;
use App\Http\Resources\Api\V1\ProductVariantResource;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * El catalogo de Vender (/products/sellable) armado a mano, con el MISMO
 * contrato que ProductResource.
 *
 * ProductResource sigue siendo la fuente de verdad del formato de un
 * producto; esta clase existe solo por volumen. Medido en produccion
 * (Central Cell, 21-oct-2026, 956 productos activos): la maquinaria de
 * JsonResource tardaba 4.236 ms en serializar ese catalogo, y el endpoint se
 * llama cada vez que alguien entra a Vender. Armar el mismo array a mano
 * baja eso a ~40 ms.
 *
 * Las dos razones del costo, y lo que se hace distinto aca:
 *  - `hasBusinessPermission()` y `hasFeature()` se resolvian DENTRO del
 *    toArray() de cada producto (dos llamadas x 956 filas). Aca se resuelven
 *    una sola vez y se pasan al loop.
 *  - Cada fila instanciaba un JsonResource con su cadena de when()/
 *    whenLoaded(). Aca es un array literal.
 *
 * Las categorias se memoizan: en un catalogo de 1.000 productos se repiten
 * unas pocas decenas, y serializar cada una por fila era trabajo regalado.
 */
class SellableProductPayload
{
    /**
     * @param  Collection<int, Product>  $products
     * @return list<array<string, mixed>>
     */
    public static function forCatalog(Collection $products, Business $business, ?User $user): array
    {
        $showCost = $user?->hasBusinessPermission('inventory.view') === true;
        $variantsEnabled = $business->hasFeature('variants');
        $categories = [];

        return $products->map(
            fn (Product $product) => self::row($product, $showCost, $variantsEnabled, $categories)
        )->all();
    }

    /**
     * @param  array<int, array<string, mixed>|null>  $categories  memo por id
     * @return array<string, mixed>
     */
    private static function row(Product $product, bool $showCost, bool $variantsEnabled, array &$categories): array
    {
        $hasRecipe = $product->relationLoaded('ingredients') && $product->ingredients->isNotEmpty();
        $hasVariants = $product->hasVariants();

        $row = [
            'id' => $product->id,
            'business_id' => $product->business_id,
        ];

        // whenLoaded() omite la clave cuando la relacion no esta cargada:
        // replicarlo importa, el front distingue "sin variantes" de "no vino".
        if ($product->relationLoaded('category')) {
            $row['category'] = self::category($product, $categories);
        }

        $row['name'] = $product->name;
        $row['description'] = $product->description;
        $row['how_to_use'] = $product->how_to_use;
        $row['price'] = number_format($product->priceAt(), 2, '.', '');

        if ($showCost) {
            $row['cost_price'] = $product->cost_price;
        }

        $stock = ProductAvailability::effectiveStock($product, $hasRecipe, $variantsEnabled);

        $row['stock'] = is_finite($stock) ? (int) $stock : $product->stock;
        $row['low_stock_alert_threshold'] = $product->low_stock_alert_threshold;
        $row['track_stock'] = $product->track_stock;
        $row['is_single_sale'] = $product->is_single_sale;
        $row['is_service'] = $product->is_service;
        $row['price_varies_at_sale'] = $product->price_varies_at_sale;
        $row['duration_minutes'] = $product->duration_minutes;
        $row['sku'] = $product->sku;
        $row['image'] = $product->image;

        if ($product->relationLoaded('images')) {
            $row['images'] = ProductImageResource::collection($product->images)->resolve();
        }

        $row['is_active'] = $product->is_active;
        $row['is_published'] = (bool) $product->is_published;
        $row['online_description'] = $product->online_description;

        if ($product->relationLoaded('ingredients')) {
            $row['ingredients'] = IngredientResource::collection($product->ingredients)->resolve();
        }

        $row['has_recipe'] = $hasRecipe;

        if ($product->relationLoaded('optionGroups')) {
            $row['option_groups'] = ProductOptionGroupResource::collection($product->optionGroups)->resolve();
        }

        if ($product->relationLoaded('components')) {
            $row['components'] = $product->components->map(fn ($c) => [
                'id' => $c->id,
                'component_product_id' => $c->component_product_id,
                'ingredient_id' => $c->ingredient_id,
                'name' => $c->componentProduct?->name ?? $c->ingredient?->name,
                'unit' => $c->ingredient?->unit,
                'quantity' => (float) $c->quantity,
            ])->values()->all();
        }

        $units = ProductAvailability::comboUnits($product);
        $row['combo_stock'] = $units === null ? null : (is_finite($units) ? (int) $units : null);

        if ($product->relationLoaded('variants')) {
            $row['variants'] = ProductVariantResource::collection($product->variants)->resolve();
        }

        $row['has_variants'] = $hasVariants;
        $row['can_manage_stock'] = ! $product->is_single_sale && ! $hasRecipe && ! $hasVariants;

        return $row;
    }

    /** @param  array<int, array<string, mixed>|null>  $memo */
    private static function category(Product $product, array &$memo): ?array
    {
        $category = $product->category;

        if (! $category) {
            return null;
        }

        return $memo[$category->id] ??= (new ProductCategoryResource($category))->resolve();
    }
}
