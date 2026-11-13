<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateLinkPageRequest;
use App\Models\BusinessStoreSettings;
use App\Services\ImageProcessor;
use App\Services\LinkPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La página de enlaces del lado del comerciante: publicarla, personalizarla
 * y agregarle enlaces propios. Sin `feature:online_store` a propósito: es
 * para todos.
 *
 * Usa la fila de business_store_settings (la crea si no existe): ahí viven el
 * logo, los colores y el contacto público. Son los MISMOS campos del tema de
 * la tienda, así que un negocio que abre tienda después hereda su estilo, y
 * uno que ya la tiene edita aquí lo mismo que en el editor.
 */
class LinkPageController extends Controller
{
    public function __construct(
        private LinkPageService $linkPage,
        private ImageProcessor $images,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->state($this->settingsFor($request)));
    }

    public function update(UpdateLinkPageRequest $request): JsonResponse
    {
        $settings = $this->settingsFor($request);
        $business = $settings->business;
        $data = $request->validated();

        if (array_key_exists('enabled', $data)) {
            $settings->link_page_enabled = (bool) $data['enabled'];
        }
        if (array_key_exists('bio_links', $data)) {
            $settings->bio_links = array_values($data['bio_links'] ?? []);
        }

        $map = [
            'name' => 'store_name',
            'description' => 'description',
            'primary_color' => 'primary_color',
            'surface_color' => 'surface_color',
            'font_preset' => 'font_preset',
            'facebook_url' => 'facebook_url',
        ];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $settings->{$column} = filled($data[$input]) ? trim((string) $data[$input]) : null;
            }
        }

        // WhatsApp, Instagram y TikTok son del negocio (Ajustes → Negocio): un
        // solo dato que leen la tienda, esta página y los recibos. La tienda
        // ya no guarda copia propia, así que escribir aquí basta.
        foreach (['instagram' => 'instagram_handle', 'tiktok' => 'tiktok_handle', 'whatsapp' => 'whatsapp_number'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $business->{$column} = filled($data[$input]) ? trim((string) $data[$input]) : null;
            }
        }

        $business->save();
        $settings->save();

        return response()->json($this->state($settings->fresh()));
    }

    /**
     * Logo de la marca: el horizontal, con el nombre escrito (cabecera de la
     * tienda). Es la misma ranura que en el editor de la tienda: un negocio
     * tiene un logo, no uno por pantalla.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        return $this->storeImage($request, 'logo_path');
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        return $this->removeImage($request, 'logo_path');
    }

    /** Ícono cuadrado: el círculo de la página de enlaces. */
    public function uploadIcon(Request $request): JsonResponse
    {
        return $this->storeImage($request, 'icon_path');
    }

    public function deleteIcon(Request $request): JsonResponse
    {
        return $this->removeImage($request, 'icon_path');
    }

    private function storeImage(Request $request, string $column): JsonResponse
    {
        $request->validate([
            // `image` obliga a que sea decodificable; SVG queda fuera porque
            // admite scripts y esto se sirve en un dominio público.
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
        ]);

        $settings = $this->settingsFor($request);
        $stored = $this->images->store(
            $request->file('image'),
            "stores/{$settings->business_id}",
            maxDimension: 512,
            thumbnailDimension: null,
        );

        // `disk` es uno solo para todas las imágenes de la fila: si el
        // anterior estaba en otro disco, se borra de ese antes de cambiarlo.
        $this->images->delete($settings->disk, [$settings->{$column}]);
        $settings->update(['disk' => $stored['disk'], $column => $stored['path']]);

        return response()->json($this->state($settings->fresh()));
    }

    private function removeImage(Request $request, string $column): JsonResponse
    {
        $settings = $this->settingsFor($request);
        $this->images->delete($settings->disk, [$settings->{$column}]);
        $settings->update([$column => null]);

        return response()->json($this->state($settings->fresh()));
    }

    private function settingsFor(Request $request): BusinessStoreSettings
    {
        return BusinessStoreSettings::firstOrCreate(['business_id' => $request->user()->business_id]);
    }

    /**
     * Lo que necesita el POS: si está publicada, su dirección, la vista que
     * ve el público (para mostrar qué aparece solo) y los valores editables
     * tal como se guardaron.
     *
     * @return array<string, mixed>
     */
    private function state(BusinessStoreSettings $settings): array
    {
        $business = $settings->business;
        $theme = $settings->theme();

        return [
            'enabled' => (bool) $settings->link_page_enabled,
            'published' => $this->linkPage->isPublished($business, $settings),
            'has_store' => $this->linkPage->hasOpenStore($business, $settings),
            'public_url' => $this->linkPage->publicUrl($business),
            'page' => $this->linkPage->publicPayload($business, $settings),
            'settings' => [
                'name' => $settings->store_name,
                'business_name' => $business->name,
                'description' => $settings->description,
                'primary_color' => $theme['primary'],
                'surface_color' => $theme['surface'],
                'font_preset' => $theme['font'],
                'has_logo' => filled($settings->logo_path),
                'has_icon' => filled($settings->icon_path),
                'logo_url' => $settings->logoUrl(),
                'icon_url' => $settings->iconUrl(),
                'instagram' => $business->instagram_handle ?: $settings->instagram_url,
                'tiktok' => $business->tiktok_handle,
                'whatsapp' => $business->whatsapp_number ?: $settings->whatsapp_number,
                'facebook_url' => $settings->facebook_url,
            ],
        ];
    }
}
