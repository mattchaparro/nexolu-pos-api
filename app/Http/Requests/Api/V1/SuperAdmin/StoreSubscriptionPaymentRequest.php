<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionPaymentRequest extends FormRequest
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
            'amount_cop' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'period_label' => ['required', 'string', 'max:64'],
            'paid_at' => ['required', 'date'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:32'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
