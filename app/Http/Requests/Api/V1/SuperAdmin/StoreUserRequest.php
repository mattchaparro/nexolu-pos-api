<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'role' => ['required', 'string', Rule::in(['admin', 'employee'])],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Este correo ya está registrado en el sistema (puede pertenecer a otro negocio).',
            'business_id.required' => 'Selecciona un negocio.',
            'role.required' => 'Selecciona un rol.',
        ];
    }
}
