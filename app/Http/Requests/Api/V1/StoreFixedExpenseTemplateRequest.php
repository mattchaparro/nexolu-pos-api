<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFixedExpenseTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->business_id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $businessId = $this->user()?->business_id;

        return [
            'name' => ['required', 'string', 'max:150'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'expense_type_id' => ['sometimes', 'nullable', 'integer', BusinessScopedExists::forOrGlobal('expense_types', $businessId)],
            'active' => ['sometimes', 'boolean'],
            'day_of_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:28'],
            'scope' => ['sometimes', 'nullable', Rule::in(['operacional', 'administrativo'])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del gasto fijo es obligatorio.',
            'day_of_month.min' => 'El día del mes debe estar entre 1 y 28.',
            'day_of_month.max' => 'El día del mes debe estar entre 1 y 28.',
        ];
    }
}
