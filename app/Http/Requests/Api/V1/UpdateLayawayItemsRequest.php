<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesSaleItems;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLayawayItemsRequest extends FormRequest
{
    use ValidatesSaleItems;

    public function authorize(): bool
    {
        return (bool) $this->user()?->business_id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->saleItemRules();
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Agrega al menos un producto.',
            'items.min' => 'Agrega al menos un producto.',
            'items.*.product_id.exists' => 'Producto no encontrado.',
            'items.*.quantity.min' => 'La cantidad debe ser al menos 1.',
        ];
    }
}
