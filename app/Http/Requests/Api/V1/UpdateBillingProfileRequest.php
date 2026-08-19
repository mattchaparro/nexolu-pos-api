<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Todos los campos son opcionales a proposito (ver App\Models\BillingProfile):
 * completar el perfil de facturacion nunca es un requisito duro, ni en el
 * registro ni al pagar.
 */
class UpdateBillingProfileRequest extends FormRequest
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
            'document_type' => ['sometimes', 'nullable', 'string', 'in:CC,NIT,CE'],
            'document_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_type.in' => 'El tipo de documento debe ser CC, NIT o CE.',
            'email.email' => 'Ingresa un correo válido.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'document_type' => 'el tipo de documento',
            'document_number' => 'el número de documento',
            'full_name' => 'el nombre completo',
            'phone' => 'el teléfono',
            'email' => 'el correo',
            'address' => 'la dirección',
            'city' => 'la ciudad',
        ];
    }
}
