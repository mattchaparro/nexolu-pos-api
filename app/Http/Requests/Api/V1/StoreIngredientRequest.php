<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->business_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['required', 'string', 'max:20'],
            'stock' => ['sometimes', 'numeric', 'min:0'],
            'min_stock' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
