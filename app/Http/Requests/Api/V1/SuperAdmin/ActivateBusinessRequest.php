<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'min:1', 'max:730'],
            'amount_cop' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'plan' => ['sometimes', 'nullable', 'string', Rule::in(['basic', 'full'])],
            'custom_price_cop' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:65000'],
        ];
    }
}
