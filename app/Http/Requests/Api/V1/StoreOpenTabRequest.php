<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesSaleItems;
use App\Models\Sale;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreOpenTabRequest extends FormRequest
{
    use ValidatesSaleItems;

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
            ...$this->saleItemRules(),
            'table_id' => [
                'sometimes',
                'nullable',
                'integer',
                BusinessScopedExists::for('business_tables', $businessId),
            ],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'customer_identification' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_delivery' => ['sometimes', 'boolean'],
            'cart_discount_id' => [
                'sometimes',
                'nullable',
                'integer',
                BusinessScopedExists::for('discounts', $businessId, ['scope' => 'cart']),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateSaleItemLines($validator);

            $tableId = $this->input('table_id');
            if ($tableId && Sale::where('table_id', $tableId)->where('status', 'open')->exists()) {
                $validator->errors()->add('table_id', 'Esta mesa ya tiene una cuenta abierta.');
            }
        });
    }
}
