<?php

namespace App\Http\Requests\Api\V1;

use App\Models\BusinessStoreSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

            // --- Personalización (los mismos campos que el tema de la tienda:
            // si el negocio abre tienda después, hereda este estilo) ---
            'name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            // Hex de 6 dígitos: va directo a un style público.
            'primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'surface_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_preset' => ['sometimes', Rule::in(BusinessStoreSettings::FONT_PRESETS)],

            // --- Redes: "@usuario", "usuario" o la URL completa ---
            'instagram' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tiktok' => ['sometimes', 'nullable', 'string', 'max:120'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:20'],
            'facebook_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
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
            'primary_color.regex' => 'El color debe ser un hexadecimal como #4f46e5.',
            'surface_color.regex' => 'El fondo debe ser un hexadecimal como #ffffff.',
            'facebook_url.url' => 'Pega la dirección completa de tu página de Facebook (https://…).',
        ];
    }
}
