<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesProductVariants;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'how_to_use' => ['sometimes', 'nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
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
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'sku')->where('business_id', $businessId),
            ],
            'image' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            // Publicar en la tienda online es una decision explicita:
            // arranca en false y nunca se enciende sola.
            'is_published' => ['sometimes', 'boolean'],
            'online_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category_id' => [
                'required',
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

        if (empty($this->input('ingredients', []))) {
            return;
        }

        if ($this->boolean('is_service')) {
            $v->errors()->add('ingredients', 'Los servicios no pueden tener receta por ingredientes.');
        }

        if ($this->boolean('is_single_sale')) {
            $v->errors()->add('ingredients', 'Los productos de venta única no pueden tener receta por ingredientes.');
        }
    }

    private function validateVariantsRules(Validator $v): void
    {
        if (! $this->user()?->business?->hasFeature('variants')) {
            return;
        }

        $this->validateVariantExclusionRules(
            $v,
            $this->input('variants', []),
            $this->boolean('is_service'),
            $this->boolean('is_single_sale'),
            $this->input('ingredients', []),
        );

        $this->validateVariantPayloadRules($v);
    }
}
