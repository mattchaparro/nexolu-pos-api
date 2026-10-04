<?php

namespace App\Http\Requests\Concerns;

use App\Models\Product;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * Reglas del payload `components` al crear/editar un producto (combos). Se
 * reemplaza el conjunto completo: lo que no venga se borra. Cada pieza es un
 * producto O un insumo, nunca los dos.
 */
trait ValidatesProductComponents
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function componentRules(): array
    {
        $businessId = $this->user()?->business_id;

        return [
            'components' => ['sometimes', 'array', 'max:30'],
            'components.*.component_product_id' => ['nullable', 'integer', BusinessScopedExists::for('products', $businessId)],
            'components.*.ingredient_id' => ['nullable', 'integer', BusinessScopedExists::for('ingredients', $businessId)],
            'components.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ];
    }

    protected function validateComponentsRules(Validator $validator): void
    {
        $ownProduct = $this->route('product');
        $ownId = $ownProduct instanceof Product ? $ownProduct->id : (int) $ownProduct;

        foreach ((array) $this->input('components', []) as $i => $row) {
            $productId = $row['component_product_id'] ?? null;
            $ingredientId = $row['ingredient_id'] ?? null;

            if (($productId === null) === ($ingredientId === null)) {
                $validator->errors()->add("components.{$i}.component_product_id", 'Cada pieza del combo es un producto o un insumo.');

                continue;
            }

            if ($productId === null) {
                continue;
            }

            if ($ownId && (int) $productId === $ownId) {
                $validator->errors()->add("components.{$i}.component_product_id", 'Un combo no puede incluirse a sí mismo.');
            } elseif ((float) ($row['quantity'] ?? 0) != (int) ($row['quantity'] ?? 0)) {
                $validator->errors()->add("components.{$i}.quantity", 'La cantidad de un producto del combo debe ser un número entero.');
            } elseif (Product::where('id', $productId)->where(fn ($q) => $q->where('is_service', true))->exists()
                || Product::find($productId)?->components()->exists()) {
                $validator->errors()->add("components.{$i}.component_product_id", 'Un servicio o un combo no puede ser pieza de otro combo.');
            }
        }
    }
}
