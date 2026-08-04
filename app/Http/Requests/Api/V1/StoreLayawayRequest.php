<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesSaleItems;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLayawayRequest extends FormRequest
{
    use ValidatesSaleItems;

    public function authorize(): bool
    {
        return (bool) $this->user()?->business_id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $business = $this->user()?->business;

        return [
            ...$this->saleItemRules(),
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'initial_payment' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'initial_payment_method' => ['sometimes', 'nullable', 'string', 'max:50', Rule::in($business?->allowedPaymentMethodIds() ?? [])],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Agrega al menos un producto.',
            'items.min' => 'Agrega al menos un producto.',
            'items.*.product_id.exists' => 'Producto no encontrado.',
            'items.*.quantity.min' => 'La cantidad debe ser al menos 1.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateSaleItemLines($validator);
        });
    }
}
