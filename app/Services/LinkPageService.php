<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessStoreSettings;
use App\Support\ChannelPhone;

/**
 * La página de enlaces del negocio (la de la bio de Instagram o TikTok).
 *
 * Es para todos los negocios, no solo los que tienen tienda online: junta lo
 * que el negocio ya tiene configurado. Cada dato sale primero de la tienda
 * (si la tiene, es lo que el comerciante cuidó para el público) y si no, de
 * Ajustes del negocio. Lo único propio de esta página son `bio_links`.
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
        return $business->hasFeature('online_store') && (bool) $settings?->is_active;
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
        return ChannelPhone::normalize((string) ($settings?->whatsapp_number ?: $business->whatsapp_number ?: ''));
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
            'logo_url' => $settings?->logoUrl() ?: ($business->logo_path ? asset($business->logo_path) : null),
            'theme' => $theme,
            'has_store' => $this->hasOpenStore($business, $settings),
            'has_whatsapp' => $this->whatsappNumber($business, $settings) !== null,
            'instagram_url' => $settings?->instagram_url ?: $this->profileUrl('https://www.instagram.com/', $business->instagram_handle),
            'tiktok_url' => $this->profileUrl('https://www.tiktok.com/@', $business->tiktok_handle),
            'facebook_url' => $settings?->facebook_url,
            'address' => $settings?->address ?: $business->address,
            'opening_hours' => $settings?->opening_hours,
            'bio_links' => array_values($settings?->bio_links ?? []),
        ];
    }

    /**
     * En Ajustes el usuario escribe "@mitienda", "mitienda" o pega la URL
     * completa: los tres tienen que terminar en el mismo enlace.
     */
    private function profileUrl(string $base, ?string $handle): ?string
    {
        $handle = trim((string) $handle);
        if ($handle === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $handle)) {
            return $handle;
        }

        $handle = ltrim($handle, '@');

        return preg_match('/^[A-Za-z0-9._]{1,60}$/', $handle) ? $base.$handle : null;
    }
}
