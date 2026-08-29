<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Configuracion de la tienda online de un negocio.
 *
 * `is_active` es el interruptor del COMERCIANTE (publicar o no publicar), no
 * el del modulo: habilitarlo es decision de SuperAdmin via el feature flag
 * `online_store`. Los dos tienen que estar en verde para que la tienda
 * responda - ver App\Http\Middleware\ResolveStorefrontTenant.
 */
#[Fillable([
    'business_id', 'is_active', 'store_name', 'description', 'disk', 'logo_path', 'banner_path',
    'primary_color', 'surface_color', 'accent_color', 'font_preset',
    'whatsapp_number', 'shipping_flat_fee', 'min_order_amount', 'pickup_enabled',
    'order_email_enabled', 'order_email',
    'terms', 'seo_title', 'seo_description',
    'hero_enabled', 'hero_eyebrow', 'hero_title', 'hero_highlight', 'hero_subtitle',
    'hero_cta_label', 'hero_image_path',
    'trust_enabled', 'trust_items',
    'story_enabled', 'story_eyebrow', 'story_title', 'story_body', 'story_image_path', 'story_stats',
    'address', 'opening_hours', 'instagram_url', 'facebook_url',
])]
class BusinessStoreSettings extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $table = 'business_store_settings';

    /**
     * Combinaciones tipograficas curadas. Cerradas a proposito: fuente libre
     * significa tiendas ilegibles y una peticion a Google Fonts por cada
     * capricho. El storefront traduce la clave a familias concretas.
     *
     * @var list<string>
     */
    public const FONT_PRESETS = ['moderna', 'editorial', 'calida', 'tecnica'];

    /** Semillas del tema cuando la tienda no eligio nada. */
    public const DEFAULT_PRIMARY = '#4f46e5';

    public const DEFAULT_SURFACE = '#ffffff';

    public const DEFAULT_ACCENT = '#0ea5e9';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pickup_enabled' => 'boolean',
            'order_email_enabled' => 'boolean',
            'shipping_flat_fee' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'hero_enabled' => 'boolean',
            'trust_enabled' => 'boolean',
            'story_enabled' => 'boolean',
            'trust_items' => 'array',
            'story_stats' => 'array',
        ];
    }

    /** Cae al nombre del negocio si el comerciante no puso uno propio. */
    public function displayName(): string
    {
        return $this->store_name ?: (string) $this->business?->name;
    }

    public function logoUrl(): ?string
    {
        return $this->fileUrl($this->logo_path);
    }

    public function bannerUrl(): ?string
    {
        return $this->fileUrl($this->banner_path);
    }

    public function heroImageUrl(): ?string
    {
        return $this->fileUrl($this->hero_image_path);
    }

    public function storyImageUrl(): ?string
    {
        return $this->fileUrl($this->story_image_path);
    }

    /**
     * Las tres semillas del tema, siempre completas: el storefront deriva el
     * resto de la paleta de aca y no puede recibir nulos a medias.
     *
     * @return array{primary: string, surface: string, accent: string, font: string}
     */
    public function theme(): array
    {
        return [
            'primary' => $this->primary_color ?: self::DEFAULT_PRIMARY,
            'surface' => $this->surface_color ?: self::DEFAULT_SURFACE,
            'accent' => $this->accent_color ?: self::DEFAULT_ACCENT,
            'font' => in_array($this->font_preset, self::FONT_PRESETS, true) ? $this->font_preset : 'moderna',
        ];
    }

    /**
     * Contra el disco guardado en la fila, no el configurado hoy - mismo
     * criterio que ProductImage::url().
     */
    private function fileUrl(?string $path): ?string
    {
        if (! $path || ! $this->disk) {
            return null;
        }

        return Storage::disk($this->disk)->url($path);
    }
}
