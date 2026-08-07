<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use App\Support\BusinessFeaturePresets;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessConfigRequest extends FormRequest
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
        $flagRules = [];
        foreach (array_keys(BusinessFeaturePresets::full()) as $key) {
            $flagRules["feature_flags.{$key}"] = ['sometimes', 'boolean'];
        }

        return array_merge([
            'subscription_plan' => ['required', 'string', Rule::in(['basic', 'full'])],
            'feature_flags' => ['required', 'array'],
        ], $flagRules);
    }
}
