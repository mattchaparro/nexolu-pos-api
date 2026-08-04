<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correccion administrativa de un turno (ya abierto o ya cerrado) - no pasa
 * por CashShiftService porque no es un abrir/cerrar, es editar valores ya
 * guardados (p. ej. la base inicial se digito mal).
 */
class UpdateCashShiftRequest extends FormRequest
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
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'opening_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'counted_cash' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'closing_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
