<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OpenCashShiftRequest extends FormRequest
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
            'opening_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'opening_cash.required' => 'Indica el efectivo inicial del turno.',
            'opening_cash.min' => 'El efectivo inicial no puede ser negativo.',
        ];
    }
}
