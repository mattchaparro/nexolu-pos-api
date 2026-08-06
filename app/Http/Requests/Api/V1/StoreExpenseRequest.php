<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Reminder;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'value' => ['required', 'numeric', 'min:100'],
            'scope' => ['sometimes', 'nullable', Rule::in(['operacional', 'administrativo'])],
            'payment_method' => ['sometimes', 'nullable', Rule::in(Expense::PAYMENT_METHODS)],
            'type_id' => [
                'required',
                BusinessScopedExists::forOrGlobal('expense_types', $businessId),
            ],
            'linkable_type' => ['sometimes', 'nullable', Rule::in([Product::class, Ingredient::class])],
            'linkable_id' => [
                'sometimes',
                'nullable',
                'integer',
                'required_with:linkable_type',
                BusinessScopedExists::for(
                    $this->input('linkable_type') === Ingredient::class ? 'ingredients' : 'products',
                    $businessId
                ),
            ],
            // Recordatorio de pago opcional - ExpenseController::store() lo
            // ignora si no hay reminder_date.
            'reminder_title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'reminder_date' => ['sometimes', 'nullable', 'date'],
            'reminder_recurrence' => ['sometimes', 'nullable', Rule::in(Reminder::RECURRENCES)],
            'reminder_end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:reminder_date'],
            'reminder_notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function defaults(): array
    {
        return [
            'scope' => 'operacional',
            'payment_method' => Expense::PAYMENT_METHODS[0],
        ];
    }
}
