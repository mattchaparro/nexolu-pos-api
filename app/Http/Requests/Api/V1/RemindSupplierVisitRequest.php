<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Reminder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RemindSupplierVisitRequest extends FormRequest
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
        return [
            'due_date' => ['required', 'date'],
            'recurrence' => ['sometimes', 'nullable', Rule::in(Reminder::RECURRENCES)],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:due_date'],
        ];
    }
}
