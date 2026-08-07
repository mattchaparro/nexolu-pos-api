<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // 'superadmin' (sin guion bajo): asi esta el rol en produccion
        // (roles.name), no 'super_admin' - con el guion bajo este chequeo
        // nunca podia ser cierto para ese rol.
        return (bool) $this->user()?->hasRole(['admin', 'superadmin']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'value' => ['required', 'string', 'max:255'],
        ];
    }
}
