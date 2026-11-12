<?php

namespace App\Http\Requests\Api\V1;

use App\Models\BusinessStoreSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLinkPageRequest extends FormRequest
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
            'enabled' => ['sometimes', 'boolean'],
            // Solo http(s): se pintan como enlaces en una página pública, y un
            // `javascript:` ahí sería ejecutar código en nombre del negocio.
            'bio_links' => ['sometimes', 'nullable', 'array', 'max:'.BusinessStoreSettings::MAX_BIO_LINKS],
            'bio_links.*.label' => ['required', 'string', 'max:40'],
            'bio_links.*.url' => ['required', 'url:http,https', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bio_links.max' => 'La página de enlaces admite hasta '.BusinessStoreSettings::MAX_BIO_LINKS.' enlaces propios.',
            'bio_links.*.label.required' => 'Cada enlace necesita un texto.',
            'bio_links.*.url.required' => 'Cada enlace necesita una dirección.',
            'bio_links.*.url.url' => 'La dirección del enlace debe empezar por https://',
        ];
    }
}
