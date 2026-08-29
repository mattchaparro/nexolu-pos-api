<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductAttributeRequest extends FormRequest
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

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('product_attributes', 'name')->where('business_id', $businessId),
            ],
            'values' => ['required', 'array', 'min:1'],
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
