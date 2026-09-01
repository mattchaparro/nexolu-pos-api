<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Validation\BusinessScopedExists;
use Illuminate\Foundation\Http\FormRequest;

class StoreStockTransferRequest extends FormRequest
{
    /**
     * Las dos sedes se validan contra el negocio del usuario (no basta con
     * que existan): sin eso, un id de otra empresa entraria al servicio y el
     * unico filtro seria el chequeo de negocio de alla. Dos candados, igual
     * que el resto de los FormRequest de este repo.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $businessId = $this->user()?->business_id;

        return [
            'from_branch_id' => ['required', 'integer', BusinessScopedExists::for('branches', $businessId)],
            'to_branch_id' => ['required', 'integer', 'different:from_branch_id', BusinessScopedExists::for('branches', $businessId)],
            'notes' => ['nullable', 'string', 'max:1000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.product_id' => ['nullable', 'integer', BusinessScopedExists::for('products', $businessId)],
            'items.*.product_variant_id' => ['nullable', 'integer', BusinessScopedExists::for('product_variants', $businessId)],
            'items.*.ingredient_id' => ['nullable', 'integer', BusinessScopedExists::for('ingredients', $businessId)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to_branch_id.different' => 'La sede de origen y la de destino no pueden ser la misma.',
            'items.required' => 'El traslado no tiene productos.',
            'items.*.quantity.gt' => 'La cantidad a trasladar debe ser mayor que cero.',
        ];
    }
}
