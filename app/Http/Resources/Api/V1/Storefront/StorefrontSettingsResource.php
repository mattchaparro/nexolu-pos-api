<?php

namespace App\Http\Resources\Api\V1\Storefront;

use App\Models\BusinessStoreSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Identidad publica de la tienda: lo que necesita el storefront para pintarse
 * (tema, contenido del home, contacto, condiciones) y nada mas.
 *
 * Ojo con lo que NO va aca: el negocio tiene nit, telefono del dueño, correos
 * de alerta, plan de suscripcion y flags. Nada de eso tiene por que llegar a
 * un visitante anonimo, asi que se enumera campo a campo en vez de volcar el
 * modelo.
 *
 * Los bloques del home viajan como objetos con su propio `enabled`: el
 * storefront decide que secciones dibujar sin tener que adivinar si un
 * titulo vacio significa "sin hero" o "hero a medio llenar".
 *
 * @mixin BusinessStoreSettings
 */
class StorefrontSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->business?->slug,
            'name' => $this->displayName(),
            'description' => $this->description,
            'logo_url' => $this->logoUrl(),
            'banner_url' => $this->bannerUrl(),

            // Tres semillas + preset. El storefront deriva el resto de la
            // paleta con contraste garantizado (ver su composable de tema).
            'theme' => $this->theme(),

            'whatsapp_number' => $this->whatsapp_number,
            'shipping_flat_fee' => (float) $this->shipping_flat_fee,
            'min_order_amount' => (float) $this->min_order_amount,
            'pickup_enabled' => (bool) $this->pickup_enabled,
            // Si el comprador va a pagar en la pasarela o a coordinar el
            // pago con la tienda. Solo el hecho, nunca con que proveedor:
            // eso es informacion del comercio, no del comprador.
            'accepts_online_payment' => $this->business?->activePaymentGateway() !== null,
            'terms' => $this->terms,

            'hero' => [
                // Sin titular no hay hero que dibujar, aunque este encendido.
                'enabled' => (bool) $this->hero_enabled && filled($this->hero_title),
                'eyebrow' => $this->hero_eyebrow,
                'title' => $this->hero_title,
                'highlight' => $this->hero_highlight,
                'subtitle' => $this->hero_subtitle,
                'cta_label' => $this->hero_cta_label,
                'image_url' => $this->heroImageUrl(),
            ],

            'trust' => [
                'enabled' => (bool) $this->trust_enabled && filled($this->trust_items),
                'items' => array_values($this->trust_items ?? []),
            ],

            'story' => [
                'enabled' => (bool) $this->story_enabled && filled($this->story_title),
                'eyebrow' => $this->story_eyebrow,
                'title' => $this->story_title,
                'body' => $this->story_body,
                'image_url' => $this->storyImageUrl(),
                'stats' => array_values($this->story_stats ?? []),
            ],

            'contact' => [
                'address' => $this->address,
                'opening_hours' => $this->opening_hours,
                'instagram_url' => $this->instagram_url,
                'facebook_url' => $this->facebook_url,
            ],

            'seo' => [
                'title' => $this->seo_title ?: $this->displayName(),
                'description' => $this->seo_description ?: $this->description,
            ],
        ];
    }
}
