<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Unico punto de creacion/edicion de productos. Extraido de ProductController
 * para que App\Capabilities\Products\CreateProductCapability (invocada por
 * el Nexolu IA Core) reutilice exactamente la misma logica que el endpoint
 * HTTP normal, en vez de reimplementarla.
 */
class ProductService
{
    public function __construct(private StockService $stockService) {}

    /**
     * @param  array<string, mixed>  $data  ya validado, con category_id resuelto.
     *                                      'ingredients' (opcional) es una lista [{ingredient_id, quantity}, ...]
     *                                      - ver syncIngredients().
     */
    public function create(Business $business, array $data): Product
    {
        $ingredients = $this->extractIngredients($business, $data);
        $variants = $this->extractVariants($business, $data, $ingredients);
        $data = $this->normalizeTypeFlags($data);

        $product = Product::create([
            'stock' => 0,
            'cost_price' => 0,
            ...$data,
        ]);

        if ($ingredients !== null) {
            $this->syncIngredients($product, $ingredients);
        }

        if ($variants !== null) {
            $this->syncVariants($product, $variants);
        }

        // refresh(): columnas con DEFAULT a nivel de BD que el request no mando
        // (is_active, track_stock, is_single_sale, ...) quedan null en la
        // instancia en memoria hasta releerla - create() no las repuebla solo.
        return $product->refresh()->load('category', 'ingredients', 'variants.attributeValues.productAttribute');
    }

    /**
     * Duplica un producto - puerto de
     * Admin\ProductsController::duplicate() del legacy, incluyendo el bug
     * real que ese metodo ya corregia alla: el SKU es unico por
     * (business_id, sku) y replicate() copia el de origen tal cual, asi
     * que duplicar CUALQUIER producto violaba esa unicidad de una (visto
     * en Sentry en produccion, POS-NEXOLU-S). Se busca un sufijo
     * -COPIAN libre contra withTrashed() porque el indice unico de MySQL
     * no excluye los borrados. El stock arranca en 0 (la copia no hereda
     * existencias fisicas del original); la receta (si tiene) se copia
     * con las mismas cantidades. NO copia variantes (limitacion conocida
     * de esta primera version: replicate() no toca relaciones hasMany, y
     * duplicar precio/stock/sku de cada combinacion sin que el usuario los
     * revise uno por uno no aporta) - un producto con variantes se duplica
     * sin ellas.
     */
    public function duplicate(Business $business, Product $product): Product
    {
        $baseName = preg_replace('/\s+—\sCopia\s\d+$/u', '', $product->name);
        $baseSku = preg_replace('/-COPIA\d+$/', '', (string) $product->sku);

        $n = 1;
        while (
            Product::withTrashed()
                ->where('business_id', $business->id)
                ->where('sku', $baseSku.'-COPIA'.$n)
                ->exists()
        ) {
            $n++;
        }

        $copy = $product->replicate(['stock']);
        $copy->name = $baseName.' — Copia '.$n;
        $copy->sku = $baseSku.'-COPIA'.$n;
        $copy->stock = 0;
        $copy->save();

        $ingredients = $business->hasFeature('ingredients') ? $product->ingredients : collect();
        if ($ingredients->isNotEmpty()) {
            $this->syncIngredients($copy, $ingredients->map(fn ($i) => [
                'ingredient_id' => $i->id,
                'quantity' => (float) $i->pivot->quantity,
            ])->all());
        }

        return $copy->refresh()->load('category', 'ingredients');
    }

    /** @param  array<string, mixed>  $data */
    /**
     * @param  ?User  $actor  quien edita. Necesario para que un cambio de stock
     *                        de variante quede auditado como StockMovement; sin
     *                        el, el stock se escribe directo (ver syncVariants).
     */
    public function update(Business $business, Product $product, array $data, ?User $actor = null): Product
    {
        $ingredients = $this->extractIngredients($business, $data, $product);
        $variants = $this->extractVariants($business, $data, $ingredients, $product);
        $data = $this->normalizeTypeFlags($data, $product);

        $product->update($data);

        if ($ingredients !== null) {
            $this->syncIngredients($product, $ingredients);
        }

        if ($variants !== null) {
            $this->syncVariants($product, $variants, $actor);
        }

        return $product->fresh()->load('category', 'ingredients', 'variants.attributeValues.productAttribute');
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
     * Saca 'variants' de $data (por referencia) y decide si hay que
     * sincronizar las variantes. Devuelve null cuando no hay nada que
     * sincronizar (la clave no vino en la request), o la lista final a
     * sincronizar en caso contrario - vacia si el producto es servicio, es
     * de venta unica, el negocio no tiene el feature, o el producto ya
     * quedo con receta de ingredientes (precedencia is_service ->
     * is_single_sale -> ingredients -> variants, mismo orden que ya usa
     * extractIngredients() para is_service/is_single_sale - variantes e
     * ingredientes son mutuamente excluyentes, y ante un payload que manda
     * ambos no vacios gana ingredients).
     *
     * $ingredients debe ser el resultado YA normalizado de
     * extractIngredients() (llamar este metodo despues de ese) para poder
     * chequear la exclusion mutua correctamente.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{ingredient_id: int, quantity: float}>|null  $ingredients
     * @return list<array{id?: int, sku: string, price: float, cost_price?: float, stock?: int, low_stock_alert_threshold?: ?int, is_active?: bool, attribute_value_ids: list<int>}>|null
     */
    private function extractVariants(Business $business, array &$data, ?array $ingredients, ?Product $product = null): ?array
    {
        if (! array_key_exists('variants', $data)) {
            return null;
        }

        $variants = $data['variants'];
        unset($data['variants']);

        $isService = (bool) ($data['is_service'] ?? $product?->is_service ?? false);
        $isSingleSale = (bool) ($data['is_single_sale'] ?? $product?->is_single_sale ?? false);

        if (! $business->hasFeature('variants') || $isService || $isSingleSale || ! empty($ingredients)) {
            $variants = [];
        }

        if (! empty($variants)) {
            $this->assertNoDuplicateVariantCombinations($variants);

            // Autoridad, no sugerencia (mismo criterio que normalizeTypeFlags()):
            // un producto con variantes siempre trackea stock (por variante,
            // no por products.stock) y nunca puede terminar siendo servicio,
            // venta unica, o de precio variable al vender.
            $data['track_stock'] = true;
            $data['is_single_sale'] = false;
            $data['is_service'] = false;
            $data['price_varies_at_sale'] = false;

            // Invariante: un producto con variantes tiene products.stock = 0,
            // SIEMPRE. Al convertir uno que ya venia funcionando, su stock
            // anterior (ej. 50 unidades) dejaba de ser vendible pero seguia en
            // la columna, y cualquier lectura cruda lo contaba de mas - fue la
            // causa real de que el valor de inventario y el reporte de
            // margenes mostraran cifras infladas para esos productos. Ponerlo
            // en 0 aca deja la invariante cierta de entrada, en vez de
            // depender de que cada consumidor futuro se acuerde de excluirlos.
            $data['stock'] = 0;
        }

        return $variants;
    }

    /**
     * La unicidad de la combinacion COMPLETA de atributos (que no existan
     * dos variantes con exactamente el mismo set de attribute_value_ids) no
     * es expresable como constraint SQL simple - el numero de atributos por
     * producto es variable. Se valida aca comparando sets ordenados.
     *
     * @param  list<array{attribute_value_ids: list<int>}>  $variants
     */
    private function assertNoDuplicateVariantCombinations(array $variants): void
    {
        $seen = [];

        foreach ($variants as $variant) {
            $ids = $variant['attribute_value_ids'] ?? [];
            sort($ids);
            $key = implode(',', $ids);

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'variants' => 'Hay combinaciones de variante repetidas.',
                ]);
            }

            $seen[$key] = true;
        }
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

    /**
     * Upsert por id (fila con id existente -> update(), sin id -> create());
     * las variantes de $product cuyo id no vino en $variants se borran
     * (soft-delete, ver ProductVariant::SoftDeletes - sale_items/
     * stock_movements pueden seguir apuntando a una variante borrada).
     *
     * El stock de una variante QUE YA EXISTE no se escribe directo: la
     * diferencia se registra como StockMovement de ajuste, igual que si se
     * hubiera hecho desde el listado del catalogo. Antes se persistia a pelo
     * y era el unico stock del sistema que cambiaba sin motivo, sin usuario y
     * sin rastro en el historial. El stock de una variante NUEVA si va
     * directo: es su stock inicial, mismo criterio que el de un producto
     * recien creado.
     *
     * @param  list<array{id?: int, sku: string, price: float, cost_price?: float, stock?: int, low_stock_alert_threshold?: ?int, is_active?: bool, attribute_value_ids: list<int>}>  $variants
     */
    private function syncVariants(Product $product, array $variants, ?User $actor = null): void
    {
        $keepIds = [];

        foreach ($variants as $row) {
            $attributeValueIds = $row['attribute_value_ids'] ?? [];
            unset($row['attribute_value_ids']);

            $id = $row['id'] ?? null;
            unset($row['id']);

            $row['business_id'] = $product->business_id;

            $variant = $id ? $product->variants()->find($id) : null;
            if ($variant) {
                $newStock = $row['stock'] ?? null;

                // Fuera del update(): lo mueve el StockMovement de abajo, y
                // escribirlo aca ademas lo contaria dos veces.
                if ($actor && $newStock !== null) {
                    unset($row['stock']);
                }

                $variant->update($row);

                if ($actor && $newStock !== null && (int) $newStock !== (int) $variant->stock) {
                    $this->stockService->variantAdjust(
                        $actor,
                        $variant,
                        (float) $newStock,
                        'Ajuste desde la edición del producto',
                    );
                }
            } else {
                $variant = $product->variants()->create($row);
            }

            $pivotData = ProductAttributeValue::whereIn('id', $attributeValueIds)
                ->pluck('product_attribute_id', 'id')
                ->map(fn (int $attributeId) => ['product_attribute_id' => $attributeId])
                ->all();
            $variant->attributeValues()->sync($pivotData);

            $keepIds[] = $variant->id;
        }

        $product->variants()->whereNotIn('id', $keepIds)->delete();
    }
}
