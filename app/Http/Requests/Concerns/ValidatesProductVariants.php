<?php

namespace App\Http\Requests\Concerns;

use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * Reglas y chequeos del payload 'variants' compartidos por
 * Store/UpdateProductRequest - a diferencia de la version mas simple de
 * 'ingredients' (que se duplica sin trait en cada request), esta logica es
 * mas pesada (unicidad de sku por fila con ignore condicional, no repetir
 * atributo dentro de una fila, no repetir la misma combinacion entre
 * filas), asi que se extrae desde el principio - mismo motivo que ya
 * justifico ValidatesSaleItems.
 */
trait ValidatesProductVariants
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function variantRules(): array
    {
        $businessId = $this->user()?->business_id;

        return [
            'variants' => ['sometimes', 'array'],
            'variants.*.id' => [
                'sometimes',
                'nullable',
                'integer',
                BusinessScopedExists::for('product_variants', $businessId),
            ],
            // Opcional: si no viene, ProductVariant::booted() lo deriva del
            // producto padre (PROD-039-1, -2, ...), igual que Product ya hacia
            // con el suyo. Exigirlo obligaba al comerciante a inventar un
            // codigo por cada combinacion de talla x color antes de guardar.
            'variants.*.sku' => ['sometimes', 'nullable', 'string', 'max:255'],
            'variants.*.price' => ['required_with:variants', 'numeric', 'min:0'],
            'variants.*.cost_price' => ['sometimes', 'numeric', 'min:0'],
            'variants.*.stock' => ['sometimes', 'integer', 'min:0'],
            'variants.*.low_stock_alert_threshold' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'variants.*.attribute_value_ids' => ['required_with:variants', 'array', 'min:1'],
            'variants.*.attribute_value_ids.*' => [
                'integer',
                BusinessScopedExists::for('product_attribute_values', $businessId),
            ],
        ];
    }

    /**
     * Exclusion mutua de variantes con is_service/is_single_sale/ingredients
     * - mismos 3 casos que ProductService::extractVariants() aplica en
     * silencio del lado del servicio (para cuando lo llama directo
     * CreateProductCapability, sin pasar por aca), pero con mensaje de
     * validacion temprano y por campo para el cliente HTTP normal.
     *
     * @param  array<int, mixed>  $effectiveVariants
     * @param  array<int, mixed>  $effectiveIngredients
     */
    protected function validateVariantExclusionRules(
        Validator $v,
        array $effectiveVariants,
        bool $isService,
        bool $isSingleSale,
        array $effectiveIngredients,
    ): void {
        if (empty($effectiveVariants)) {
            return;
        }

        if ($isService) {
            $v->errors()->add('variants', 'Los servicios no pueden tener variantes.');
        }

        if ($isSingleSale) {
            $v->errors()->add('variants', 'Los productos de venta única no pueden tener variantes.');
        }

        if (! empty($effectiveIngredients)) {
            $v->errors()->add('variants', 'Un producto no puede tener variantes y receta de ingredientes a la vez.');
        }
    }

    /**
     * SKU único por fila (scoped al negocio, ignorando la propia fila en
     * edicion), un atributo no puede repetirse dentro de la misma fila, y
     * dos filas no pueden compartir exactamente la misma combinacion de
     * valores (chequeo temprano - ProductService::assertNoDuplicateVariantCombinations()
     * es la ultima linea de defensa si esto se saltea, ej. desde
     * CreateProductCapability).
     */
    protected function validateVariantPayloadRules(Validator $v): void
    {
        $variants = $this->input('variants', []);
        if (! is_array($variants) || $variants === []) {
            return;
        }

        $businessId = $this->user()?->business_id;
        $seenCombos = [];

        foreach ($variants as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $sku = $row['sku'] ?? null;
            if ($sku) {
                $query = ProductVariant::where('business_id', $businessId)->where('sku', $sku);
                if (! empty($row['id'])) {
                    $query->whereKeyNot($row['id']);
                }
                if ($query->exists()) {
                    $v->errors()->add("variants.{$i}.sku", 'Ya existe una variante con ese SKU.');
                }
            }

            $ids = $row['attribute_value_ids'] ?? [];
            if (! is_array($ids) || $ids === []) {
                continue;
            }

            $attributeIds = ProductAttributeValue::whereIn('id', $ids)->pluck('product_attribute_id');
            if ($attributeIds->count() !== $attributeIds->unique()->count()) {
                $v->errors()->add("variants.{$i}.attribute_value_ids", 'Esta variante repite el mismo atributo con dos valores distintos.');

                continue;
            }

            $comboKey = collect($ids)->sort()->implode(',');
            if (isset($seenCombos[$comboKey])) {
                $v->errors()->add("variants.{$i}.attribute_value_ids", 'Esta combinación ya está usada por otra variante.');
            }
            $seenCombos[$comboKey] = true;
        }
    }
}
