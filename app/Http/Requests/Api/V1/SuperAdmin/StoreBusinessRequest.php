<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use App\Support\BusinessFeaturePresets;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta completa de un negocio desde el panel: los mismos datos del registro
 * publico mas todo lo que se pacta en una llamada de ventas (features
 * sueltos, dias de prueba, activacion inmediata con su pago, precio
 * especial). A diferencia del wizard publico, aca los feature flags NO se
 * clampan contra el plan - un superadmin puede darle una funcion suelta a un
 * negocio Basico; para eso existe el panel.
 */
class StoreBusinessRequest extends FormRequest
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
        $flagRules = [];
        foreach (array_keys(BusinessFeaturePresets::full()) as $key) {
            $flagRules["feature_flags.{$key}"] = ['sometimes', 'boolean'];
        }

        return array_merge([
            'business_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:15'],
            'whatsapp_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'nit' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'plan' => ['sometimes', 'nullable', 'string', Rule::in(['basic', 'full'])],
            'feature_flags' => ['sometimes', 'nullable', 'array'],
            // 0 dias es valido: el negocio entra pagando, sin periodo de prueba.
            'trial_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            // Activacion inmediata: mismos campos que
            // BusinessesController::activate(), opcionales aca porque un alta
            // tambien puede quedar solo en prueba.
            'activate_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'amount_cop' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'custom_price_cop' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // El dueño no eligio su contraseña: sin este correo no tiene como
            // entrar (el de bienvenida no la lleva).
            'send_credentials' => ['sometimes', 'boolean'],
        ], $flagRules);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_name.required' => 'El nombre del negocio es obligatorio.',
            'owner_name.required' => 'El nombre del dueño es obligatorio.',
            'email.required' => 'El correo del dueño es obligatorio.',
            'email.unique' => 'Ya existe un usuario con este correo.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ];
    }
}
