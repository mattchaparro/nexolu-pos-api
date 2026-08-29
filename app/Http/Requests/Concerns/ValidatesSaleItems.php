<?php

namespace App\Http\Requests\Concerns;

use App\Models\Product;
use App\Support\ProductAvailability;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Validation\Validator;

/**
 * Reglas y chequeos de "items" compartidos por toda request que arma o edita
 * el carrito de una venta (venta directa, abrir cuenta, agregar/sincronizar
 * items de una cuenta abierta). Vivia repetido 1:1 en cada request antes de
 * este trait.
 */
trait ValidatesSaleItems
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function saleItemRules(): array
    {
        $businessId = $this->user()?->business_id;

        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                BusinessScopedExists::for('products', $businessId),
            ],
            'items.*.product_variant_id' => [
                'nullable',
                'integer',
                BusinessScopedExists::for('product_variants', $businessId),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_id' => [
                'nullable',
                'integer',
                BusinessScopedExists::for('discounts', $businessId, ['scope' => 'item']),
            ],
        ];
    }

    /**
     * Aviso temprano (fuera de transaccion): precio obligatorio si el producto
     * varia al vender, y stock suficiente segun la lectura actual. La verdad
     * final se re-verifica bajo lock en SaleService::applyItems().
     */
    protected function validateSaleItemLines(Validator $validator): void
    {
        $items = $this->input('items', []);
        if (! is_array($items)) {
            return;
        }

        // ingredientsEnabled + with('ingredients'): mismo criterio que
        // ProductAvailability::forBusiness() - sin esto, effectiveStock()
        // no puede calcular el stock real de un producto con receta (queda
        // en products.stock, que para esos productos siempre es 0, ver el
        // docblock de ProductAvailability). Antes este metodo comparaba
        // directo contra esa columna fantasma: cualquier producto con
        // receta con track_stock=true rechazaba la venta con "stock
        // insuficiente" sin importar cuanto insumo hubiera disponible.
        $ingredientsEnabled = (bool) $this->user()?->business?->hasFeature('ingredients');
        $variantsEnabled = (bool) $this->user()?->business?->hasFeature('variants');

        $products = Product::where('business_id', $this->user()?->business_id)
            ->whereIn('id', collect($items)->pluck('product_id')->filter()->values())
            ->when($ingredientsEnabled, fn ($q) => $q->with('ingredients'))
            ->when($variantsEnabled, fn ($q) => $q->with('variants'))
            ->get()
            ->keyBy('id');

        $canApplyDiscounts = $this->user()?->hasBusinessPermission('discounts.apply') ?? false;

        foreach ($items as $i => $item) {
            $product = $products->get($item['product_id'] ?? null);
            if (! $product) {
                continue;
            }

            $variantId = $item['product_variant_id'] ?? null;
            $variant = $variantId ? $product->variants->firstWhere('id', (int) $variantId) : null;

            if ($variantsEnabled && $product->hasVariants()) {
                if (! $variantId) {
                    $validator->errors()->add("items.{$i}.product_variant_id", 'Selecciona una variante para «'.$product->name.'».');

                    continue;
                }

                if (! $variant || ! $variant->is_active) {
                    $validator->errors()->add("items.{$i}.product_variant_id", 'Variante inválida para «'.$product->name.'».');

                    continue;
                }
            }

            if ($product->price_varies_at_sale
                && (! array_key_exists('unit_price', $item) || $item['unit_price'] === '' || $item['unit_price'] === null)) {
                $validator->errors()->add("items.{$i}.unit_price", 'Indica el precio para «'.$product->name.'».');

                continue;
            }

            $quantity = (int) ($item['quantity'] ?? 0);
            $availableStock = $variant
                ? ProductAvailability::effectiveVariantStock($variant)
                : ProductAvailability::effectiveStock($product, $ingredientsEnabled, $variantsEnabled);

            if (($variant ? true : $product->track_stock) && $quantity > $availableStock) {
                $validator->errors()->add(
                    "items.{$i}.quantity",
                    'No hay stock suficiente para «'.$product->name.'» (disponible: '.(int) $availableStock.').'
                );
            }

            if (! empty($item['discount_id']) && ! $canApplyDiscounts) {
                $validator->errors()->add("items.{$i}.discount_id", 'No tienes permiso para aplicar descuentos.');
            }
        }
    }
}
