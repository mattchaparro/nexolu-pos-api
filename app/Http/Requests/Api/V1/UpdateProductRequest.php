<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesProductVariants;
use App\Models\Product;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    use ValidatesProductVariants;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->business_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $businessId = $this->user()?->business_id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'how_to_use' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer'],
            'low_stock_alert_threshold' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'track_stock' => ['sometimes', 'boolean'],
            'is_single_sale' => ['sometimes', 'boolean'],
            'is_service' => ['sometimes', 'boolean'],
            'price_varies_at_sale' => ['sometimes', 'boolean'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sku' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('products', 'sku')
                    ->where('business_id', $businessId)
                    ->ignore($this->route('product')),
            ],
            'image' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            // Publicar en la tienda online es una decision explicita:
            // arranca en false y nunca se enciende sola.
            'is_published' => ['sometimes', 'boolean'],
            'online_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category_id' => [
                'sometimes',
                BusinessScopedExists::for('product_categories', $businessId),
            ],
            'ingredients' => ['sometimes', 'array'],
            'ingredients.*.ingredient_id' => [
                'required_with:ingredients',
                BusinessScopedExists::for('ingredients', $businessId),
            ],
            'ingredients.*.quantity' => ['required_with:ingredients', 'numeric', 'min:0.001'],
            ...$this->variantRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $this->validateIngredientsRules($v);
            $this->validateVariantsRules($v);
        });
    }

    private function validateIngredientsRules(Validator $v): void
    {
        if (! $this->user()?->business?->hasFeature('ingredients')) {
            return;
        }

        $product = $this->route('product');

        // Estado EFECTIVO tras esta request: lo que viene en el payload si
        // se mando, si no lo que el producto ya tiene guardado - a
        // diferencia de validar solo contra el estado persistido, esto
        // permite quitar la receta y marcar is_service/is_single_sale en la
        // misma request.
        $effectiveIngredients = $this->has('ingredients')
            ? $this->input('ingredients', [])
            : ($product instanceof Product ? $product->ingredients()->get(['ingredients.id'])->all() : []);

        if (empty($effectiveIngredients)) {
            return;
        }

        $isService = $this->boolean('is_service', $product instanceof Product ? $product->is_service : false);
        $isSingleSale = $this->boolean('is_single_sale', $product instanceof Product ? $product->is_single_sale : false);

        if ($isService) {
            $v->errors()->add('ingredients', 'Los servicios no pueden tener receta por ingredientes.');
        }

        if ($isSingleSale) {
            $v->errors()->add('is_single_sale', 'Quita primero la receta de ingredientes para usar venta única.');
        }
    }

    private function validateVariantsRules(Validator $v): void
    {
        if (! $this->user()?->business?->hasFeature('variants')) {
            return;
        }

        $product = $this->route('product');

        // Mismo criterio "estado efectivo" que validateIngredientsRules():
        // lo que vino en el payload si se mando, si no lo que el producto ya
        // tiene guardado - permite quitar variantes y marcar
        // is_service/is_single_sale en la misma request.
        $effectiveVariants = $this->has('variants')
            ? $this->input('variants', [])
            : ($product instanceof Product ? $product->variants()->get(['product_variants.id'])->all() : []);

        $effectiveIngredients = $this->has('ingredients')
            ? $this->input('ingredients', [])
            : ($product instanceof Product ? $product->ingredients()->get(['ingredients.id'])->all() : []);

        $isService = $this->boolean('is_service', $product instanceof Product ? $product->is_service : false);
        $isSingleSale = $this->boolean('is_single_sale', $product instanceof Product ? $product->is_single_sale : false);

        $this->validateVariantExclusionRules($v, $effectiveVariants, $isService, $isSingleSale, $effectiveIngredients);

        if ($this->has('variants')) {
            $this->validateVariantPayloadRules($v);
        }
    }
}
