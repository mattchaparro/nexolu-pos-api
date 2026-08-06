<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Reminder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReminderRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'due_date' => ['required', 'date'],
            'notify_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'notify_whatsapp' => ['sometimes', 'nullable', 'boolean'],
            'recurrence' => ['sometimes', 'nullable', Rule::in(Reminder::RECURRENCES)],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:due_date'],
        ];
    }
}
