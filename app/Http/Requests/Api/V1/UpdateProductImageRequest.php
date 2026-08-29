<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Que la variante sea de este producto lo verifica el controller,
            // que ya tiene el producto resuelto y escopeado al negocio.
            'product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
