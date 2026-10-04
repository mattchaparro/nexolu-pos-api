<?php

namespace App\Http\Requests\Api\V1;

use App\Services\CashShiftService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class OpenCashShiftRequest extends FormRequest
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
        ];
    }

    public function messages(): array
    {
        return [
            'opening_cash.required' => 'Indica el efectivo inicial del turno.',
            'opening_cash.min' => 'El efectivo inicial no puede ser negativo.',
        ];
    }

    /**
     * Abrir con una base distinta a la que dejo el ultimo cierre no se
     * bloquea: el cajero declara lo que encuentra en la caja, no lo que el
     * sistema espera. Pero se exige explicarlo, para que el faltante quede
     * escrito con nombre y hora cuando se detecta, y no aparezca un dia
     * despues en el cierre (ver CashShiftService::expectedOpeningCash).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $businessId = (int) $this->user()?->business_id;

            if ($validator->errors()->has('opening_cash') || $businessId === 0) {
                return;
            }

            $service = app(CashShiftService::class);
            $expected = $service->expectedOpeningCash($businessId, $service->drawerBranchId($businessId), includeSameDay: true);

            if ($expected === null) {
                return;
            }

            $declared = (float) $this->input('opening_cash');

            if (abs($declared - $expected['amount']) < 0.01 || trim((string) $this->input('opening_note')) !== '') {
                return;
            }

            $validator->errors()->add('opening_note', sprintf(
                'El efectivo inicial (%s) no coincide con %s del %s (%s). Anota qué pasó con la diferencia.',
                $this->formatCop($declared),
                $expected['source'] === 'shift' ? 'el efectivo con que cerró el último turno' : 'la base que dejó el cierre',
                $expected['closing_date']->format('d/m/Y'),
                $this->formatCop($expected['amount']),
            ));
        });
    }

    private function formatCop(float $amount): string
    {
        return '$'.number_format($amount, 0, ',', '.');
    }
}
