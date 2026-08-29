<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StockMovement;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->business_id;
    }

    /**
     * 'quantity' cambia de significado segun 'type': para entry/exit es la
     * magnitud a mover (siempre positiva); para adjustment es el nuevo stock
     * absoluto que debe quedar - igual que StockService::adjust().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $businessId = $this->user()?->business_id;

        return [
            'product_id' => ['required', 'integer', BusinessScopedExists::for('products', $businessId)],
            // Presente = el movimiento es de esa variante y no del producto.
            // Que pertenezca al product_id lo verifica el controller, que ya
            // tiene ambos resueltos y escopeados al negocio.
            'product_variant_id' => [
                'sometimes',
                'nullable',
                'integer',
                BusinessScopedExists::for('product_variants', $businessId),
            ],
            'type' => ['required', Rule::in([
                StockMovement::TYPE_ENTRY,
                StockMovement::TYPE_EXIT,
                StockMovement::TYPE_ADJUSTMENT,
            ])],
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit_cost_cop' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'stock_movement_reason_id' => [
                'sometimes',
                'nullable',
                BusinessScopedExists::forOrGlobal('stock_movement_reasons', $businessId),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
