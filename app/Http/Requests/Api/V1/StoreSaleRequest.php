<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesSaleItems;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSaleRequest extends FormRequest
{
    use ValidatesSaleItems;

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
        $business = $this->user()?->business;

        return [
            ...$this->saleItemRules(),
            'payment_method' => ['nullable', 'string', 'max:50', Rule::in($business?->allowedPaymentMethodIds() ?? [])],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'customer_identification' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_delivery' => ['sometimes', 'boolean'],
            'is_non_revenue' => ['sometimes', 'boolean'],
            'non_revenue_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cart_discount_id' => [
                'nullable',
                'integer',
                BusinessScopedExists::for('discounts', $businessId, ['scope' => 'cart']),
            ],
            // Los montos de los cargos los calcula el servidor desde la config
            // del negocio; el cliente solo puede renunciar a un cargo habilitado.
            'apply_service_charge' => ['sometimes', 'boolean'],
            'apply_ipoconsumo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_method.in' => 'Metodo de pago no valido.',
            'items.required' => 'Agrega al menos un producto.',
            'items.min' => 'Agrega al menos un producto.',
            'items.*.product_id.exists' => 'Producto no encontrado.',
            'items.*.quantity.min' => 'La cantidad debe ser al menos 1.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $isNonRevenue = $this->boolean('is_non_revenue');
            if (! $isNonRevenue && ! $this->filled('payment_method')) {
                $validator->errors()->add('payment_method', 'Selecciona un metodo de pago.');
            }

            $this->validateSaleItemLines($validator);
        });
    }
}
