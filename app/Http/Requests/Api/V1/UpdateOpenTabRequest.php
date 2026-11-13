<?php

namespace App\Http\Requests\Api\V1;

use App\Models\BusinessTable;
use App\Models\Sale;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Nota de una cuenta abierta y cambio de mesa. Solo a una mesa activa y
 * libre: dos cuentas abiertas en la misma mesa no se pueden distinguir en
 * la pantalla de mesas.
 */
class UpdateOpenTabRequest extends FormRequest
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
            'table_id' => ['sometimes', 'nullable', 'integer', BusinessScopedExists::for('business_tables', $this->user()?->business_id)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
            // A donde va el domicilio (de esta venta; la ficha del cliente
            // guarda la habitual aparte).
            'delivery_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Cambiar tambien la direccion habitual en la ficha del cliente.
            'save_address_to_client' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $tableId = (int) $this->input('table_id');
            /** @var Sale $sale */
            $sale = $this->route('sale');
            if (! $tableId || $tableId === (int) $sale->table_id) {
                return;
            }

            if (! BusinessTable::whereKey($tableId)->where('is_active', true)->exists()) {
                $validator->errors()->add('table_id', 'Esa mesa no está disponible.');

                return;
            }

            if (Sale::where('table_id', $tableId)->where('status', 'open')->exists()) {
                $validator->errors()->add('table_id', 'Esa mesa ya tiene una cuenta abierta.');
            }
        });
    }
}
