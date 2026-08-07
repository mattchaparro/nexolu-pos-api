<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceWorkflowStageRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:80'],
            'color' => ['required', 'string', 'max:20'],
            'is_initial' => ['sometimes', 'boolean'],
            'actions' => ['sometimes', 'nullable', 'array'],
            'actions.*.type' => ['string', Rule::in(['trigger_on_payment_complete', 'mark_order_paid'])],
        ];
    }
}
