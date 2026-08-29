<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Reminder;
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
            // Recordatorio de pago opcional, solo tiene sentido si la compra
            // queda a credito - PurchaseController::store() lo ignora si no
            // hay payment_reminder_date, aunque is_credit venga true.
            'payment_reminder_title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'payment_reminder_date' => ['sometimes', 'nullable', 'date'],
            'payment_reminder_recurrence' => ['sometimes', 'nullable', Rule::in(Reminder::RECURRENCES)],
            'payment_reminder_end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:payment_reminder_date'],
            'payment_reminder_notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer', BusinessScopedExists::for('products', $businessId)],
            'lines.*.product_variant_id' => ['nullable', 'integer', BusinessScopedExists::for('product_variants', $businessId)],
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
     * entero de unidades (igual que exigia el legacy), y si el producto
     * tiene variantes, la linea debe elegir una variante propia de ese
     * producto (no se compra stock directo del padre - ver
     * PurchaseService::applyVariantLine()).
     */
    private function validateLineItemRules(Validator $v): void
    {
        $businessId = $this->user()?->business_id;
        $variantsEnabled = (bool) $this->user()?->business?->hasFeature('variants');

        $productIds = collect($this->input('lines', []))->pluck('product_id')->filter()->unique()->values();
        $productsWithVariants = $variantsEnabled && $productIds->isNotEmpty()
            ? Product::where('business_id', $businessId)->whereIn('id', $productIds)->withCount('variants')->get()->keyBy('id')
            : collect();

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

                $product = $productsWithVariants->get((int) $line['product_id']);
                if ($product && $product->variants_count > 0) {
                    $variantId = $line['product_variant_id'] ?? null;
                    if (empty($variantId)) {
                        $v->errors()->add("lines.{$i}.product_variant_id", 'Selecciona una variante para «'.$product->name.'».');
                    } elseif (! ProductVariant::where('id', $variantId)->where('product_id', $product->id)->exists()) {
                        $v->errors()->add("lines.{$i}.product_variant_id", 'Variante inválida para «'.$product->name.'».');
                    }
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
