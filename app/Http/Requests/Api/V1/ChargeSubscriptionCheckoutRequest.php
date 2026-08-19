<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el payment_method de un cobro de checkout de suscripcion via
 * Nexolu Payments Core (flow="api"), con reglas condicionales segun el tipo
 * - ver docs/PLAN_METODOS_PAGO_ALTERNOS.md seccion 3 para la forma exacta
 * que exige el Core (y, por debajo, Wompi) de cada uno.
 */
class ChargeSubscriptionCheckoutRequest extends FormRequest
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
            'payment_method' => ['required', 'array'],
            'payment_method.type' => ['required', 'string', 'in:CARD,NEQUI,PSE,BANCOLOMBIA_TRANSFER,PAYMENT_SOURCE'],

            // CARD: token ya generado por el frontend tokenizando directo
            // con Wompi (nunca con este backend).
            'payment_method.token' => ['required_if:payment_method.type,CARD', 'string'],
            // Compartido por CARD y PAYMENT_SOURCE.
            'payment_method.installments' => ['sometimes', 'integer', 'min:1'],

            // NEQUI: celular colombiano de 10 digitos que empieza en 3.
            'payment_method.phone_number' => ['required_if:payment_method.type,NEQUI', 'regex:/^3\d{9}$/'],

            // PSE: datos del pagador + banco elegido (de GET /pse/financial-institutions).
            'payment_method.user_type' => ['required_if:payment_method.type,PSE', 'integer', 'in:0,1'],
            'payment_method.user_legal_id_type' => ['required_if:payment_method.type,PSE', 'string', 'max:10'],
            'payment_method.user_legal_id' => ['required_if:payment_method.type,PSE', 'string', 'max:30'],
            'payment_method.financial_institution_code' => ['required_if:payment_method.type,PSE', 'string'],
            'payment_method.customer_full_name' => ['required_if:payment_method.type,PSE', 'string', 'max:120'],
            'payment_method.customer_phone_number' => ['required_if:payment_method.type,PSE', 'string', 'max:20'],

            // PSE y BANCOLOMBIA_TRANSFER comparten payment_description (Wompi: max 64 caracteres, sin comillas simples).
            'payment_method.payment_description' => [
                'required_if:payment_method.type,PSE,BANCOLOMBIA_TRANSFER',
                'string',
                'max:64',
                'not_regex:/\'/',
            ],
            // El Core lo exige siempre para BANCOLOMBIA_TRANSFER, sin default.
            'payment_method.ecommerce_url' => ['required_if:payment_method.type,BANCOLOMBIA_TRANSFER', 'url'],

            // PAYMENT_SOURCE: fuente de pago guardada (ver BusinessPaymentSourceController).
            'payment_method.payment_source_id' => ['required_if:payment_method.type,PAYMENT_SOURCE', 'string'],
        ];
    }

    /**
     * Mensajes en español y por campo: los genericos de Laravel (en ingles,
     * sin traduccion en este repo - ver lang/) mostrarian literal la ruta
     * "payment_method.customer_full_name" en vez de algo entendible por el
     * usuario final.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Falta indicar el método de pago.',
            'payment_method.type.required' => 'Selecciona un método de pago.',
            'payment_method.type.in' => 'Ese método de pago no está disponible.',

            'payment_method.token.required_if' => 'Falta la tarjeta: intenta ingresarla de nuevo.',

            'payment_method.phone_number.required_if' => 'Ingresa tu número de celular para pagar con Nequi.',
            'payment_method.phone_number.regex' => 'El número de celular debe tener 10 dígitos y empezar por 3.',

            'payment_method.user_type.required_if' => 'Selecciona si eres persona natural o jurídica.',
            'payment_method.user_legal_id_type.required_if' => 'Selecciona tu tipo de documento.',
            'payment_method.user_legal_id.required_if' => 'Ingresa tu número de documento.',
            'payment_method.financial_institution_code.required_if' => 'Selecciona tu banco.',
            'payment_method.customer_full_name.required_if' => 'Ingresa tu nombre completo.',
            'payment_method.customer_phone_number.required_if' => 'Ingresa tu número de celular.',

            'payment_method.payment_description.required_if' => 'Falta la descripción del pago.',
            'payment_method.payment_description.not_regex' => 'La descripción del pago no puede contener comillas simples (\').',

            'payment_method.ecommerce_url.required_if' => 'Falta la URL de retorno del Botón Bancolombia.',
            'payment_method.ecommerce_url.url' => 'La URL de retorno del Botón Bancolombia no es válida.',

            'payment_method.payment_source_id.required_if' => 'Selecciona una fuente de pago guardada.',
        ];
    }

    /**
     * Nombre en español de cada campo para sustituir el placeholder
     * `:attribute` en cualquier regla (string/integer/max/...) que no
     * tenga un mensaje especifico en messages() - evita que se cuele la
     * ruta cruda "payment_method.xxx" en un mensaje generico.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'payment_method.type' => 'el método de pago',
            'payment_method.token' => 'la tarjeta',
            'payment_method.installments' => 'el número de cuotas',
            'payment_method.phone_number' => 'el número de celular',
            'payment_method.user_type' => 'el tipo de persona',
            'payment_method.user_legal_id_type' => 'el tipo de documento',
            'payment_method.user_legal_id' => 'el número de documento',
            'payment_method.financial_institution_code' => 'el banco',
            'payment_method.customer_full_name' => 'el nombre completo',
            'payment_method.customer_phone_number' => 'el número de celular',
            'payment_method.payment_description' => 'la descripción del pago',
            'payment_method.ecommerce_url' => 'la URL de retorno',
            'payment_method.payment_source_id' => 'la fuente de pago',
        ];
    }
}
