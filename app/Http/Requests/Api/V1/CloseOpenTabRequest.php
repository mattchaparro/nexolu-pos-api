<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CloseOpenTabRequest extends FormRequest
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
     * Que payment_method sea uno de los medios configurados del negocio, y
     * que los montos de un pago dividido cuadren con el total, lo valida
     * OpenTabService::close() - depende de si hay abonos y de cual es el
     * saldo pendiente, algo que esta request no puede saber sin duplicar esa
     * logica.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:50'],
            'payment_splits' => ['sometimes', 'nullable', 'array'],
            'payment_splits.*.method' => ['required_with:payment_splits', 'string', 'max:50'],
            'payment_splits.*.amount' => ['nullable', 'numeric', 'min:0'],
            'payment_splits.*.label' => ['nullable', 'string', 'max:120'],
            'is_non_revenue' => ['sometimes', 'boolean'],
            'non_revenue_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'customer_identification' => ['sometimes', 'nullable', 'string', 'max:50'],
            'apply_service_charge' => ['sometimes', 'boolean'],
            'apply_ipoconsumo' => ['sometimes', 'boolean'],
        ];
    }
}
