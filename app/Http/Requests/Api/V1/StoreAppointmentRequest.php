<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
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
            'services' => ['required', 'array', 'min:1'],
            'services.*.id' => ['required', 'integer', BusinessScopedExists::for('products', $businessId)],
            'services.*.custom_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'user_id' => ['sometimes', 'nullable', 'integer', BusinessScopedExists::for('users', $businessId)],
            'client_name' => ['required', 'string', 'max:150'],
            'client_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'client_email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'initial_payment' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }
}
