<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Expense;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'lines.*.product_id' => ['required', 'integer', BusinessScopedExists::for('products', $businessId)],
            // Solo lineas de producto (no ingrediente) por ahora - cantidad
            // siempre entera, igual que exigia el legacy para productos.
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.line_total_cop' => ['required', 'numeric', 'min:0.01'],
            'lines.*.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Agrega al menos una línea de compra.',
            'lines.min' => 'Agrega al menos una línea de compra.',
            'lines.*.product_id.exists' => 'Producto no encontrado.',
            'lines.*.quantity.min' => 'La cantidad debe ser al menos 1.',
            'lines.*.line_total_cop.min' => 'El valor pagado debe ser mayor que 0.',
        ];
    }
}
