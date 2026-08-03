<?php

namespace App\Http\Requests\Concerns;

use App\Models\Product;
use Illuminate\Validation\Rule;
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
                Rule::exists('products', 'id')->where('business_id', $businessId),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_id' => [
                'nullable',
                'integer',
                Rule::exists('discounts', 'id')->where('business_id', $businessId)->where('scope', 'item'),
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

        $products = Product::where('business_id', $this->user()?->business_id)
            ->whereIn('id', collect($items)->pluck('product_id')->filter()->values())
            ->get()
            ->keyBy('id');

        foreach ($items as $i => $item) {
            $product = $products->get($item['product_id'] ?? null);
            if (! $product) {
                continue;
            }

            if ($product->price_varies_at_sale
                && (! array_key_exists('unit_price', $item) || $item['unit_price'] === '' || $item['unit_price'] === null)) {
                $validator->errors()->add("items.{$i}.unit_price", 'Indica el precio para «'.$product->name.'».');

                continue;
            }

            $quantity = (int) ($item['quantity'] ?? 0);
            if ($product->track_stock && $quantity > $product->stock) {
                $validator->errors()->add(
                    "items.{$i}.quantity",
                    'No hay stock suficiente para «'.$product->name.'» (disponible: '.(int) $product->stock.').'
                );
            }
        }
    }
}
