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

        // Redes: viven en el negocio (Ajustes → Negocio) para que salgan en
        // todo lado. Si la tienda tiene su propio Instagram/WhatsApp, ese
        // gana en la página pública (LinkPageService), así que se actualiza
        // también: si no, el cambio no se vería.
        if (array_key_exists('instagram', $data)) {
            $business->instagram_handle = filled($data['instagram']) ? trim((string) $data['instagram']) : null;
            if (filled($settings->instagram_url)) {
                $settings->instagram_url = $this->linkPage->instagramUrl($business->instagram_handle);
            }
        }
        if (array_key_exists('tiktok', $data)) {
            $business->tiktok_handle = filled($data['tiktok']) ? trim((string) $data['tiktok']) : null;
        }
        if (array_key_exists('whatsapp', $data)) {
            $business->whatsapp_number = filled($data['whatsapp']) ? trim((string) $data['whatsapp']) : null;
            if (filled($settings->whatsapp_number)) {
                $settings->whatsapp_number = $business->whatsapp_number;
            }
        }

        $business->save();
        $settings->save();

        return response()->json($this->state($settings->fresh()));
    }

    /**
     * Logo de la página. Es la misma ranura que el logo de la tienda: un
     * negocio tiene un logo, no uno por pantalla.
     */
    public function uploadLogo(Request $request): JsonResponse
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

        $this->images->delete($settings->disk, [$settings->logo_path]);
        $settings->update(['disk' => $stored['disk'], 'logo_path' => $stored['path']]);

        return response()->json($this->state($settings->fresh()));
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        $settings = $this->settingsFor($request);
        $this->images->delete($settings->disk, [$settings->logo_path]);
        $settings->update(['logo_path' => null]);

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
                'instagram' => $business->instagram_handle,
                'tiktok' => $business->tiktok_handle,
                'whatsapp' => $settings->whatsapp_number ?: $business->whatsapp_number,
                'facebook_url' => $settings->facebook_url,
            ],
        ];
    }
}
