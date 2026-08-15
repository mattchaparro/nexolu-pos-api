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
            'payment_method.type' => ['required', 'string', 'in:CARD,NEQUI,PSE,BANCOLOMBIA_TRANSFER'],

            // CARD: token ya generado por el frontend tokenizando directo
            // con Wompi (nunca con este backend).
            'payment_method.token' => ['required_if:payment_method.type,CARD', 'string'],
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
            'payment_method.ecommerce_url' => ['sometimes', 'nullable', 'url'],
        ];
    }
}
