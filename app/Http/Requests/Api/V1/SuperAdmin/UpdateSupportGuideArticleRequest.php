<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupportGuideArticleRequest extends FormRequest
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
            'support_guide_category_id' => ['required', 'integer', 'exists:support_guide_categories,id'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('support_guide_articles', 'slug')->ignore($this->route('article'))],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'body' => ['required', 'string', 'max:65000'],
            'suggested_route' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:32767'],
            'is_active' => ['sometimes', 'boolean'],
            'module_feature' => ['sometimes', 'nullable', 'string', 'max:64'],
            'visible_to' => ['sometimes', 'nullable', 'string', 'in:all,admin_only,employee_only'],
        ];
    }
}
