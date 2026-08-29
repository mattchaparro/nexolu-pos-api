<?php

namespace App\Http\Requests\Api\V1;

use App\Models\BusinessStoreSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessStoreSettingsRequest extends FormRequest
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
            'is_active' => ['sometimes', 'boolean'],
            'store_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Hex de 6 digitos: el color va directo a un style del storefront,
            // asi que aceptar texto libre seria inyectar CSS de terceros.
            'primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'surface_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // Catalogo cerrado: la clave se traduce a familias concretas en el
            // storefront, asi que un valor libre romperia la tipografia.
            'font_preset' => ['sometimes', Rule::in(BusinessStoreSettings::FONT_PRESETS)],
            'whatsapp_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'shipping_flat_fee' => ['sometimes', 'numeric', 'min:0'],
            'min_order_amount' => ['sometimes', 'numeric', 'min:0'],
            'pickup_enabled' => ['sometimes', 'boolean'],
            'order_email_enabled' => ['sometimes', 'boolean'],
            // Vacio = al correo del dueño (ver OrderService::notifyMerchant).
            'order_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:255'],

            // --- Hero ---
            'hero_enabled' => ['sometimes', 'boolean'],
            'hero_eyebrow' => ['sometimes', 'nullable', 'string', 'max:80'],
            'hero_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'hero_highlight' => ['sometimes', 'nullable', 'string', 'max:60'],
            'hero_subtitle' => ['sometimes', 'nullable', 'string', 'max:400'],
            'hero_cta_label' => ['sometimes', 'nullable', 'string', 'max:40'],

            // --- Franja de servicios: exactamente lo que cabe en una fila ---
            'trust_enabled' => ['sometimes', 'boolean'],
            'trust_items' => ['sometimes', 'nullable', 'array', 'max:3'],
            'trust_items.*.icon' => ['required_with:trust_items', 'string', 'max:30'],
            'trust_items.*.title' => ['required_with:trust_items', 'string', 'max:60'],
            'trust_items.*.text' => ['sometimes', 'nullable', 'string', 'max:160'],

            // --- Historia ---
            'story_enabled' => ['sometimes', 'boolean'],
            'story_eyebrow' => ['sometimes', 'nullable', 'string', 'max:80'],
            'story_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'story_body' => ['sometimes', 'nullable', 'string', 'max:1200'],
            'story_stats' => ['sometimes', 'nullable', 'array', 'max:4'],
            'story_stats.*.value' => ['required_with:story_stats', 'string', 'max:12'],
            'story_stats.*.label' => ['required_with:story_stats', 'string', 'max:40'],

            // --- Pie ---
            'address' => ['sometimes', 'nullable', 'string', 'max:200'],
            'opening_hours' => ['sometimes', 'nullable', 'string', 'max:120'],
            // url estricta: son enlaces que se pintan en la tienda publica.
            'instagram_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'facebook_url' => ['sometimes', 'nullable', 'url', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'primary_color.regex' => 'El color debe ser un hexadecimal como #4f46e5.',
            'surface_color.regex' => 'El color de fondo debe ser un hexadecimal como #ffffff.',
            'accent_color.regex' => 'El color de acento debe ser un hexadecimal como #0ea5e9.',
            'trust_items.max' => 'La franja admite hasta 3 servicios.',
            'story_stats.max' => 'Puedes destacar hasta 4 cifras.',
        ];
    }
}
