<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseTypeRequest extends FormRequest
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
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('expense_types', 'name')->where('business_id', $this->user()?->business_id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del tipo es obligatorio.',
            'name.unique' => 'Ya existe un tipo con ese nombre para este negocio.',
        ];
    }
}
