<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDiscountRequest extends FormRequest
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
            // Campos de CUPON: solo los usa la tienda online. Un descuento
            // del mostrador los deja todos nulos.
            'code' => [
                'sometimes', 'nullable', 'string', 'max:40', 'alpha_dash',
                Rule::unique('discounts', 'code')->where('business_id', $businessId)->ignore($this->route('discount')),
            ],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'min_order_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('discounts', 'name')
                    ->where('business_id', $businessId)
                    ->ignore($this->route('discount')),
            ],
            'type' => ['sometimes', Rule::in(['percentage', 'fixed'])],
            'value' => [
                'sometimes',
                'numeric',
                'min:0.01',
                ...($this->input('type', $this->route('discount')?->type) === 'percentage' ? ['max:100'] : []),
            ],
            'scope' => ['sometimes', Rule::in(['item', 'cart'])],
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
