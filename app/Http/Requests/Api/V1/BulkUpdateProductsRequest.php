<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BulkUpdateProductsRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'distinct', BusinessScopedExists::for('products', $businessId)],
            'items.*.new_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'items.*.new_stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'items.*.new_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.new_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $ids = collect($this->input('items', []))->pluck('product_id');

            $blocked = Product::whereIn('id', $ids)
                ->where(fn ($q) => $q->where('is_single_sale', true)->orWhere('is_service', true))
                ->exists();

            if ($blocked) {
                $v->errors()->add('items', 'El ajuste masivo no aplica a productos de venta única ni a servicios.');
            }
        });
    }
}
