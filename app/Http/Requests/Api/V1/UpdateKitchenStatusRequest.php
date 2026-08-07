<?php

namespace App\Http\Requests\Api\V1;

use App\Services\KitchenBoardService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateKitchenStatusRequest extends FormRequest
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
            'kitchen_status' => ['required', Rule::in(KitchenBoardService::STATUSES)],
            'sale_item_ids' => ['sometimes', 'array'],
            'sale_item_ids.*' => ['integer'],
        ];
    }
}
