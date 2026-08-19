<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBusinessPaymentSourceRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:CARD,NEQUI'],
            'token' => ['required', 'string'],
            'label' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Selecciona el tipo de fuente de pago.',
            'type.in' => 'El tipo de fuente de pago debe ser tarjeta o Nequi.',
            'token.required' => 'Falta tokenizar la tarjeta o el Nequi antes de guardarlo.',
            'label.required' => 'Ponle un nombre a esta fuente de pago.',
            'label.max' => 'El nombre no puede tener más de 120 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'el tipo',
            'token' => 'la tarjeta o Nequi',
            'label' => 'el nombre',
        ];
    }
}
