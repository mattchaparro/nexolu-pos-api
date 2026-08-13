<?php

namespace App\Http\Requests\Api\V1;

use App\Support\BusinessFeaturePresets;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
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
            'business_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // Opcional a proposito: solo se pide si el negocio usa un numero
            // DISTINTO al de WhatsApp para facturas/reportes (ver
            // RegisterView.vue) - si no se manda, BusinessRegistrationService
            // lo deja igual al whatsapp_number ya verificado.
            'phone' => ['sometimes', 'nullable', 'string', 'max:15'],
            // Obligatorio: es el canal por el que el negocio recibe TODAS sus
            // notificaciones (recordatorios, resumen diario, alertas de
            // inventario) - el wizard de registro lo verifica con un OTP
            // justo despues de crear la cuenta (ver AiChannelLinkController),
            // lo que de paso sirve como verificacion anti-bot minima: nadie
            // termina el registro sin poder recibir un codigo por WhatsApp.
            'whatsapp_number' => ['required', 'string', 'max:20'],
            'nit' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'setup_mode' => ['sometimes', 'nullable', 'string', Rule::in(BusinessFeaturePresets::setupModes())],
            // Registro publico plan-primero (wizard del frontend): el usuario
            // elige 'basic'/'full' y opcionalmente apaga banderas que ese
            // plan trae encendidas por defecto - ver
            // BusinessRegistrationService::register() para el clamp que
            // impide prender una bandera fuera del plan desde aca.
            'plan' => ['sometimes', 'nullable', 'string', Rule::in(['basic', 'full'])],
            'feature_flags' => ['sometimes', 'nullable', 'array'],
            'feature_flags.*' => ['boolean'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_name.required' => 'El nombre del negocio es obligatorio.',
            'owner_name.required' => 'El nombre del dueño es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'email.unique' => 'Este correo ya está registrado.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }
}
