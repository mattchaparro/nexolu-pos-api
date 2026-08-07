<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCashClosingRequest extends FormRequest
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
            'date' => ['required', 'date', 'before_or_equal:today'],
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'base_for_next_day' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'La fecha es obligatoria.',
            'date.before_or_equal' => 'No puedes cerrar caja de un día futuro.',
            'actual_cash.required' => 'Ingresa el efectivo real en caja.',
            'actual_cash.min' => 'El valor no puede ser negativo.',
            'opening_cash.required' => 'Ingresa la base inicial del día.',
            'base_for_next_day.required' => 'Ingresa la base para el siguiente día.',
        ];
    }
}
