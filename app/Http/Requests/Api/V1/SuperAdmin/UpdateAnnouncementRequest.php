<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAnnouncementRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:1000'],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'cta_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'audience' => ['required', 'string', Rule::in(['all', 'admin', 'employee'])],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
