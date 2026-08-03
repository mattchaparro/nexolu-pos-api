<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->business_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $businessId = $this->user()?->business_id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'how_to_use' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer'],
            'low_stock_alert_threshold' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'track_stock' => ['sometimes', 'boolean'],
            'is_single_sale' => ['sometimes', 'boolean'],
            'is_service' => ['sometimes', 'boolean'],
            'price_varies_at_sale' => ['sometimes', 'boolean'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sku' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('products', 'sku')
                    ->where('business_id', $businessId)
                    ->ignore($this->route('product')),
            ],
            'image' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'category_id' => [
                'sometimes',
                BusinessScopedExists::for('product_categories', $businessId),
            ],
        ];
    }
}
