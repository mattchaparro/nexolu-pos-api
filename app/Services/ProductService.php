<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Product;

/**
 * Unico punto de creacion/edicion de productos. Extraido de ProductController
 * para que App\Capabilities\Products\CreateProductCapability (invocada por
 * el Nexolu IA Core) reutilice exactamente la misma logica que el endpoint
 * HTTP normal, en vez de reimplementarla.
 */
class ProductService
{
    /**
     * @param  array<string, mixed>  $data  ya validado, con category_id resuelto.
     *                                      'ingredients' (opcional) es una lista [{ingredient_id, quantity}, ...]
     *                                      - ver syncIngredients().
     */
    public function create(Business $business, array $data): Product
    {
        $ingredients = $this->extractIngredients($business, $data);
        $data = $this->normalizeTypeFlags($data);

        $product = Product::create([
            'stock' => 0,
            'cost_price' => 0,
            ...$data,
        ]);

        if ($ingredients !== null) {
            $this->syncIngredients($product, $ingredients);
        }

        // refresh(): columnas con DEFAULT a nivel de BD que el request no mando
        // (is_active, track_stock, is_single_sale, ...) quedan null en la
        // instancia en memoria hasta releerla - create() no las repuebla solo.
        return $product->refresh()->load('category', 'ingredients');
    }

    /** @param  array<string, mixed>  $data */
    public function update(Business $business, Product $product, array $data): Product
    {
        $ingredients = $this->extractIngredients($business, $data, $product);
        $data = $this->normalizeTypeFlags($data, $product);

        $product->update($data);

        if ($ingredients !== null) {
            $this->syncIngredients($product, $ingredients);
        }

        return $product->fresh()->load('category', 'ingredients');
    }

    /**
     * Saca 'ingredients' de $data (por referencia) y decide si hay que
     * sincronizar la receta. Devuelve null cuando no hay nada que
     * sincronizar (la clave no vino en la request), o la lista final a
     * sincronizar en caso contrario - vacia si el producto es servicio, es
     * de venta unica, o el negocio no tiene el feature, sin importar lo que
     * el cliente haya mandado: un servicio o una venta unica nunca puede
     * terminar con una receta persistida.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{ingredient_id: int, quantity: float}>|null
     */
    private function extractIngredients(Business $business, array &$data, ?Product $product = null): ?array
    {
        if (! array_key_exists('ingredients', $data)) {
            return null;
        }

        $ingredients = $data['ingredients'];
        unset($data['ingredients']);

        $isService = (bool) ($data['is_service'] ?? $product?->is_service ?? false);
        $isSingleSale = (bool) ($data['is_single_sale'] ?? $product?->is_single_sale ?? false);

        if (! $business->hasFeature('ingredients') || $isService || $isSingleSale) {
            $ingredients = [];
        }

        // Un producto con receta activa gestiona su stock por ingrediente,
        // no por products.stock (ver Product::isStockManagedByIngredientsRecipe) -
        // pero igual necesita track_stock=true para que SaleService/
        // OpenTabService lo traten como producto con inventario.
        if (! empty($ingredients)) {
            $data['track_stock'] = true;
        }

        return $ingredients;
    }

    /**
     * Un servicio nunca controla inventario, nunca es "venta unica" (esa
     * distincion es propia de bienes) y no usa alerta de stock bajo - igual
     * que Admin\ProductsController::store() del legacy. Se normaliza aca (no
     * solo confiar en que el cliente mande los valores correctos) porque
     * ProductService es el unico punto de creacion/edicion de productos,
     * tambien usado por App\Capabilities\Products\CreateProductCapability.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeTypeFlags(array $data, ?Product $product = null): array
    {
        $isService = (bool) ($data['is_service'] ?? $product?->is_service ?? false);
        if ($isService) {
            $data['track_stock'] = false;
            $data['is_single_sale'] = false;
            $data['low_stock_alert_threshold'] = null;
        }

        return $data;
    }

    /**
     * @param  list<array{ingredient_id: int, quantity: float}>  $ingredients
     */
    private function syncIngredients(Product $product, array $ingredients): void
    {
        $product->ingredients()->sync(
            collect($ingredients)->mapWithKeys(fn (array $row) => [
                $row['ingredient_id'] => ['quantity' => $row['quantity']],
            ])->all()
        );

        $product->syncRecipeCost();
    }
}
