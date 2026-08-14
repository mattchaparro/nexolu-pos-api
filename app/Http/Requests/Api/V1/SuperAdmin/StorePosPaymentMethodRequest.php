<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePosPaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // 'key' lo fija SuperAdmin a mano (no se auto-deriva del label):
            // queda grabado para siempre en sales.payment_method,
            // receivables.payment_method, etc de cada negocio que lo use -
            // no puede depender de como se llame el label hoy.
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('pos_payment_methods', 'key')],
            'label' => ['required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
