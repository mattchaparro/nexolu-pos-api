<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDiscountRequest extends FormRequest
{
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
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('discounts', 'name')->where('business_id', $businessId),
            ],
            'type' => ['required', Rule::in(['percentage', 'fixed'])],
            'value' => [
                'required',
                'numeric',
                'min:0.01',
                ...($this->input('type') === 'percentage' ? ['max:100'] : []),
            ],
            'scope' => ['required', Rule::in(['item', 'cart'])],
            'product_id' => [
                'sometimes',
                'nullable',
                'integer',
                BusinessScopedExists::for('products', $businessId),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
