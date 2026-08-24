<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessNotificationsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) $user?->business_id && ($user->is_business_owner || $user->hasRole('admin'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['array'],
            'preferences.*' => ['boolean'],
            // HH:mm 24h - los <input type="time"> del frontend ya emiten
            // este formato, sin segundos.
            'schedule' => ['array'],
            'schedule.*' => ['nullable', 'date_format:H:i'],
        ];
    }
}
