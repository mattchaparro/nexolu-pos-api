<?php

namespace App\Http\Resources\Api\V1;

use App\Models\BusinessStoreInteraction;
use App\Models\BusinessStoreSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessStoreSettings
 */
class BusinessStoreSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'is_active' => (bool) $this->is_active,
            // Crudo y resuelto: el formulario necesita saber si el
            // comerciante eligio una sede o esta heredando la principal,
            // y la pantalla necesita saber cual es en cualquier caso.
            'fulfillment_branch_id' => $this->fulfillment_branch_id,
            'resolved_fulfillment_branch_id' => $this->fulfillmentBranchId(),
            'store_name' => $this->store_name,
            'description' => $this->description,
            'logo_url' => $this->logoUrl(),
            'banner_url' => $this->bannerUrl(),
            'primary_color' => $this->primary_color,
            'surface_color' => $this->surface_color,
            'accent_color' => $this->accent_color,
            'font_preset' => $this->font_preset,
            'whatsapp_number' => $this->whatsapp_number,
            'shipping_flat_fee' => (float) $this->shipping_flat_fee,
            'shipping_note' => $this->shipping_note,
            'delivery_estimate' => $this->delivery_estimate,
            'min_order_amount' => (float) $this->min_order_amount,
            'pickup_enabled' => (bool) $this->pickup_enabled,
            // La pagina de inicio, tal cual esta guardada: con los ids de
            // imagen SIN resolver, al reves que el Resource publico. El
            // editor necesita el id para saber que foto esta elegida; el
            // comprador necesita la URL para pintarla.
            'home_blocks' => array_values($this->home_blocks ?? []),
            // Cuanta gente escribio por WhatsApp desde la tienda en los
            // ultimos 30 dias. Se expone aca y no en un endpoint aparte
            // porque es un numero suelto que se lee en la misma pantalla:
            // una consulta mas para un entero no se justifica.
            'whatsapp_clicks_30d' => BusinessStoreInteraction::withoutGlobalScopes()
                ->where('business_id', $this->business_id)
                ->where('type', BusinessStoreInteraction::TYPE_WHATSAPP)
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            'order_email_enabled' => (bool) $this->order_email_enabled,
            'order_email' => $this->order_email,
            'terms' => $this->terms,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,

            'hero_enabled' => (bool) $this->hero_enabled,
            'hero_eyebrow' => $this->hero_eyebrow,
            'hero_title' => $this->hero_title,
            'hero_highlight' => $this->hero_highlight,
            'hero_subtitle' => $this->hero_subtitle,
            'hero_cta_label' => $this->hero_cta_label,
            'hero_image_url' => $this->heroImageUrl(),

            'trust_enabled' => (bool) $this->trust_enabled,
            'trust_items' => array_values($this->trust_items ?? []),

            'story_enabled' => (bool) $this->story_enabled,
            'story_eyebrow' => $this->story_eyebrow,
            'story_title' => $this->story_title,
            'story_body' => $this->story_body,
            'story_image_url' => $this->storyImageUrl(),
            'story_stats' => array_values($this->story_stats ?? []),

            'address' => $this->address,
            'opening_hours' => $this->opening_hours,
            'instagram_url' => $this->instagram_url,
            'facebook_url' => $this->facebook_url,
            // Para que la pantalla pueda mostrar (y copiar) la direccion
            // publica sin tener que componerla a mano.
            'public_url' => rtrim((string) config('app.storefront_url'), '/').'/'.$this->business?->slug,
            'published_products_count' => $this->business?->products()
                ->withoutGlobalScopes()
                ->where('business_id', $this->business_id)
                ->where('is_published', true)
                ->where('is_active', true)
                ->count() ?? 0,
        ];
    }
}
