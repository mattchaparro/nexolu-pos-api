<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCashClosingRequest extends FormRequest
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
        return [
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'base_for_next_day' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'actual_cash.required' => 'Ingresa el efectivo real en caja.',
            'opening_cash.required' => 'Ingresa la base inicial del día.',
            'base_for_next_day.required' => 'Ingresa la base para el siguiente día.',
        ];
    }
}
