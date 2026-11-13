<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Support\ChannelPhone;
use App\Support\SocialProfile;

/**
 * La página de enlaces del negocio (la de la bio de Instagram o TikTok).
 *
 * Es para todos los negocios, no solo los que tienen tienda online: junta lo
 * que el negocio ya tiene configurado. WhatsApp, Instagram y TikTok son del
 * negocio (un solo dato para todo); nombre, descripción, logos y estilo son
 * de la marca y se comparten con la tienda. Lo único propio de esta página
 * son `bio_links`.
 */
class LinkPageService
{
    public function settingsFor(Business $business): ?BusinessStoreSettings
    {
        return $business->storeSettings()->withoutGlobalScopes()->first();
    }

    /** Tienda online visible: módulo encendido y publicada por el comerciante. */
    public function hasOpenStore(Business $business, ?BusinessStoreSettings $settings): bool
    {
        return $business->hasStorefront() && (bool) $settings?->is_active;
    }

    /**
     * Publicada si el comerciante la activó, o si ya tiene la tienda abierta
     * (en ese caso el negocio ya es público y la página no expone nada nuevo).
     */
    public function isPublished(Business $business, ?BusinessStoreSettings $settings): bool
    {
        return (bool) $business->active
            && ((bool) $settings?->link_page_enabled || $this->hasOpenStore($business, $settings));
    }

    public function whatsappNumber(Business $business, ?BusinessStoreSettings $settings): ?string
    {
        return ChannelPhone::normalize((string) ($business->whatsapp_number ?: $settings?->whatsapp_number ?: ''));
    }

    public function publicUrl(Business $business): string
    {
        return rtrim((string) config('app.storefront_url'), '/').'/'.$business->slug.'/enlaces';
    }

    /**
     * Lo que pinta la página pública. Se enumera campo a campo: el negocio
     * tiene nit, teléfono del dueño y correos que no tienen por qué salir.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(Business $business, ?BusinessStoreSettings $settings): array
    {
        $theme = ($settings ?? new BusinessStoreSettings)->theme();

        return [
            'slug' => $business->slug,
            'name' => $settings?->store_name ?: $business->name,
            'description' => $settings?->description,
            // Dos logos: el de la marca (horizontal, con el nombre) y un ícono
            // cuadrado. La página usa el ícono en su círculo; si no hay,
            // pinta el logo sin recortarlo.
            'logo_url' => $settings?->logoUrl() ?: ($business->logo_path ? asset($business->logo_path) : null),
            'icon_url' => $settings?->iconUrl(),
            'theme' => $theme,
            'has_store' => $this->hasOpenStore($business, $settings),
            // 'menu' para restaurantes: el botón lleva a la carta, no a una tienda.
            'kind' => $business->storefrontKind(),
            // Tienda solo de menú: el botón de la página dice "Ver el menú".
            'menu_mode' => (bool) $settings?->menu_mode,
            'has_whatsapp' => $this->whatsappNumber($business, $settings) !== null,
            'instagram_url' => SocialProfile::instagram($business->instagram_handle)
                ?? SocialProfile::instagram($settings?->instagram_url),
            'tiktok_url' => SocialProfile::tiktok($business->tiktok_handle),
            'facebook_url' => $settings?->facebook_url,
            'address' => $settings?->address ?: $business->address,
            'opening_hours' => $settings?->opening_hours,
            'bio_links' => array_values($settings?->bio_links ?? []),
        ];
    }
}
