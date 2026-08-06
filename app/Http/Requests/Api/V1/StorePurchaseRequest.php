<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Expense;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePurchaseRequest extends FormRequest
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
        $businessId = $this->user()?->business_id;

        return [
            'supplier_id' => ['sometimes', 'nullable', 'integer', BusinessScopedExists::for('suppliers', $businessId)],
            'purchased_at' => ['required', 'date'],
            'invoice_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_credit' => ['sometimes', 'boolean'],
            'create_expense' => ['sometimes', 'boolean'],
            'expense_payment_method' => ['sometimes', 'nullable', 'string', Rule::in(Expense::PAYMENT_METHODS)],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer', BusinessScopedExists::for('products', $businessId)],
            'lines.*.ingredient_id' => ['nullable', 'integer', BusinessScopedExists::for('ingredients', $businessId)],
            // numeric, no integer: una linea de ingrediente admite cantidades
            // fraccionarias (kg, litros...) - validateLineItemRules() exige
            // entero solo cuando la linea es de producto.
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.line_total_cop' => ['required', 'numeric', 'min:0.01'],
            'lines.*.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $this->validateLineItemRules($v);
        });
    }

    /**
     * Cada linea es de producto O de ingrediente, nunca las dos ni ninguna -
     * mismas columnas mutuamente excluyentes que purchase_lines/StockMovement.
     * Para una linea de producto, ademas, la cantidad debe ser un numero
     * entero de unidades (igual que exigia el legacy).
     */
    private function validateLineItemRules(Validator $v): void
    {
        foreach ($this->input('lines', []) as $i => $line) {
            $hasProduct = ! empty($line['product_id'] ?? null);
            $hasIngredient = ! empty($line['ingredient_id'] ?? null);

            if ($hasProduct === $hasIngredient) {
                $v->errors()->add("lines.{$i}", 'Cada línea debe tener solo producto o solo ingrediente.');

                continue;
            }

            if ($hasProduct) {
                $quantity = (float) ($line['quantity'] ?? 0);
                if (abs($quantity - round($quantity)) > 0.0001) {
                    $v->errors()->add("lines.{$i}.quantity", 'La cantidad de un producto debe ser un número entero de unidades.');
                }
            }
        }
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Agrega al menos una línea de compra.',
            'lines.min' => 'Agrega al menos una línea de compra.',
            'lines.*.product_id.exists' => 'Producto no encontrado.',
            'lines.*.ingredient_id.exists' => 'Ingrediente no encontrado.',
            'lines.*.quantity.min' => 'La cantidad debe ser mayor que 0.',
            'lines.*.line_total_cop.min' => 'El valor pagado debe ser mayor que 0.',
        ];
    }
}
