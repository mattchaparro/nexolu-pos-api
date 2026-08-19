<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InitiateSubscriptionCheckoutRequest extends FormRequest
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
            'redirect_url' => ['required', 'url'],
            // 'widget' (default, legado) o 'api' (tokenizacion/Nequi/PSE/
            // Boton Bancolombia embebidos - ver docs/PLAN_METODOS_PAGO_ALTERNOS.md).
            'flow' => ['sometimes', 'string', 'in:widget,api'],
        ];
    }
}
