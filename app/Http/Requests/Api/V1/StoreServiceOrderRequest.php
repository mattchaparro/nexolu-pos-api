<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreServiceOrderRequest extends FormRequest
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
            'client_id' => ['sometimes', 'nullable', 'integer', BusinessScopedExists::for('clients', $businessId)],
            'appointment_id' => ['sometimes', 'nullable', 'integer', BusinessScopedExists::for('appointments', $businessId)],
            'product_id' => ['sometimes', 'nullable', 'integer', BusinessScopedExists::for('products', $businessId)],
            'service_name' => ['required', 'string', 'max:200'],
            'total' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'nullable', 'array'],
            'items.*.name' => ['required', 'string', 'max:200'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.user_id' => ['sometimes', 'nullable', 'integer', BusinessScopedExists::for('users', $businessId)],
            'items.*.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'initial_payment' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'initial_payment_method' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (empty($this->input('items')) && ! $this->filled('total')) {
                $validator->errors()->add('total', 'Indica un total o el detalle de items.');
            }
        });
    }
}
