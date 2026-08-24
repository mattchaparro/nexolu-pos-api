<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
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
            // El match contra la contraseña actual se verifica en el
            // controller (Hash::check), no con la regla current_password:web
            // - la sesion aca es por token Sanctum, no por guard de sesion
            // 'web', y esa regla resuelve el usuario autenticado contra el
            // guard que se le pase, no necesariamente el mismo que ya
            // resolvio el middleware auth:sanctum.
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Ingresa tu contraseña actual.',
            'password.required' => 'La contraseña nueva es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }
}
