<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePrintSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'auto_print_receipts' => ['sometimes', 'boolean'],
            'receipt_copies' => ['sometimes', 'integer', Rule::in([1, 2])],
            'open_cash_drawer' => ['sometimes', 'boolean'],
        ];
    }
}
