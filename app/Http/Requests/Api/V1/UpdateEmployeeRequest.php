<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->business_id && $this->user()->hasRole('admin');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('employee'))],
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'role' => ['sometimes', 'nullable', Rule::in(['employee', 'admin'])],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
            // A que sedes entra. Es una cosa distinta de los permisos: estos
            // dicen QUE puede hacer y las sedes DONDE. Solo se toca si viene
            // la clave, para que el formulario de un negocio monosede (que no
            // la manda) no le borre las asignaciones a nadie.
            'branch_ids' => ['sometimes', 'array'],
            'branch_ids.*' => ['integer', BusinessScopedExists::for('branches', $this->user()?->business_id)],
        ];
    }
}
