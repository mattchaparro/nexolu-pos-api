<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
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
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:60'],
            'type_id' => [
                'sometimes',
                Rule::exists('expense_types', 'id')->where(
                    fn ($query) => $query->where(
                        fn ($sub) => $sub->where('business_id', $businessId)->orWhereNull('business_id')
                    )
                ),
            ],
            'linkable_type' => ['sometimes', 'nullable', Rule::in([Product::class])],
            'linkable_id' => [
                'sometimes',
                'nullable',
                'integer',
                'required_with:linkable_type',
                Rule::exists('products', 'id')->where('business_id', $businessId),
            ],
        ];
    }
}
