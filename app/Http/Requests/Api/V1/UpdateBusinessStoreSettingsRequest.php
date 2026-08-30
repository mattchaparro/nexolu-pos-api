<?php

namespace App\Http\Requests\Api\V1;

use App\Models\BusinessStoreSettings;
use App\Support\StoreHomeBlocks;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // El home: una lista ordenada de bloques tipados. Cada tipo
            // trae sus reglas de StoreHomeBlocks para que agregar un bloque
            // nuevo no obligue a tocar este archivo.
            'home_blocks' => ['sometimes', 'array', 'max:'.StoreHomeBlocks::MAX_BLOCKS],
            'home_blocks.*.id' => ['required', 'string', 'max:40'],
            'home_blocks.*.type' => ['required', Rule::in(StoreHomeBlocks::types())],
            'home_blocks.*.enabled' => ['sometimes', 'boolean'],

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

    /**
     * Reglas dependientes del TIPO de cada bloque.
     *
     * Laravel no sabe validar "segun el valor de otro campo del mismo
     * elemento del array", asi que las reglas de cada tipo se agregan aca,
     * ya sabiendo que tipo declaro cada bloque.
     */
    public function withValidator(Validator $validator): void
    {
        $blocks = $this->input('home_blocks');
        if (! is_array($blocks)) {
            return;
        }

        $porTipo = [];
        $reglas = [];

        foreach ($blocks as $index => $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if (! is_string($type) || ! isset(StoreHomeBlocks::rules()[$type])) {
                continue;
            }

            $porTipo[$type] = ($porTipo[$type] ?? 0) + 1;

            // Las de su tipo, mas las de presentacion, que valen para todos.
            $campos = [...StoreHomeBlocks::rules()[$type], ...StoreHomeBlocks::presentationRules()];

            foreach ($campos as $campo => $regla) {
                $reglas["home_blocks.{$index}.{$campo}"] = $regla;
            }
        }

        $validator->addRules($reglas);

        // Dos portadas seguidas no es personalizacion, es una pagina rota.
        $validator->after(function ($validator) use ($porTipo) {
            foreach (StoreHomeBlocks::MAX_PER_TYPE as $type => $max) {
                if (($porTipo[$type] ?? 0) > $max) {
                    $validator->errors()->add(
                        'home_blocks',
                        "Solo puede haber {$max} bloque de tipo '{$type}' en la página."
                    );
                }
            }
        });
    }

    /** Descarta campos que el tipo del bloque no declara. */
    protected function passedValidation(): void
    {
        if (! is_array($this->input('home_blocks'))) {
            return;
        }

        $this->merge([
            'home_blocks' => array_values(array_map(
                fn (array $block) => StoreHomeBlocks::prune($block),
                $this->input('home_blocks'),
            )),
        ]);
    }
}
