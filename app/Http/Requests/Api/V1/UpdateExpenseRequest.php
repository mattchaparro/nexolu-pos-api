<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Expense;
use App\Models\Product;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseRequest extends FormRequest
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
            'date' => ['sometimes', 'date'],
            'description' => ['sometimes', 'string', 'max:255'],
            'value' => ['sometimes', 'numeric', 'min:100'],
            'scope' => ['sometimes', 'nullable', Rule::in(['operacional', 'administrativo'])],
            'payment_method' => ['sometimes', 'nullable', Rule::in(Expense::PAYMENT_METHODS)],
            'type_id' => [
                'sometimes',
                BusinessScopedExists::forOrGlobal('expense_types', $businessId),
            ],
            'linkable_type' => ['sometimes', 'nullable', Rule::in([Product::class])],
            'linkable_id' => [
                'sometimes',
                'nullable',
                'integer',
                'required_with:linkable_type',
                BusinessScopedExists::for('products', $businessId),
            ],
        ];
    }
}
