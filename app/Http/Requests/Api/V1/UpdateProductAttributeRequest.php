<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->business_id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $businessId = $this->user()?->business_id;
        $attributeId = $this->route('product_attribute')?->id;

        return [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('product_attributes', 'name')
                    ->where('business_id', $businessId)
                    ->ignore($attributeId),
            ],
            // Omitir 'values' deja los valores existentes intactos - mismo
            // criterio que 'ingredients' en UpdateProductRequest (ver
            // ProductAttributeController::update()).
            'values' => ['sometimes', 'array'],
            'values.*.id' => [
                'sometimes',
                'nullable',
                'integer',
                BusinessScopedExists::for('product_attribute_values', $businessId, ['product_attribute_id' => $attributeId]),
            ],
            'values.*.value' => ['required', 'string', 'max:100', 'distinct:strict'],
            'values.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe un atributo con ese nombre.',
            'values.*.value.distinct' => 'No repitas el mismo valor dos veces.',
        ];
    }
}
