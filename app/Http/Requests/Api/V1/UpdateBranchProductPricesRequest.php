<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Validation\BusinessScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchProductPricesRequest extends FormRequest
{
    /**
     * `price` nullable a proposito: null borra el override y devuelve esa
     * sede al precio del catalogo. Es distinto de 0, que es un precio valido.
     *
     * La variante se valida ademas contra ESTE producto, no solo contra el
     * negocio: sin eso se podria fijarle precio a la variante de otro
     * producto pasando por la ruta de este.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $businessId = $this->user()?->business_id;

        return [
            'prices' => ['required', 'array'],
            'prices.*.branch_id' => ['required', 'integer', BusinessScopedExists::for('branches', $businessId)],
            'prices.*.price' => ['present', 'nullable', 'numeric', 'min:0'],
            'prices.*.product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')
                    ->where('business_id', $businessId)
                    ->where('product_id', $this->route('product')?->id),
            ],
        ];
    }
}
