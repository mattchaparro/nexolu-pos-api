<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateLinkPageRequest;
use App\Models\BusinessStoreSettings;
use App\Services\LinkPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La página de enlaces del lado del comerciante: publicarla y agregarle
 * enlaces propios. Sin `feature:online_store` a propósito: es para todos.
 *
 * Usa la fila de business_store_settings (la crea si no existe) porque ahí
 * viven el logo, los colores y el contacto público que la página reutiliza.
 */
class LinkPageController extends Controller
{
    public function __construct(private LinkPageService $linkPage) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->state($this->settingsFor($request)));
    }

    public function update(UpdateLinkPageRequest $request): JsonResponse
    {
        $settings = $this->settingsFor($request);
        $data = $request->validated();

        if (array_key_exists('enabled', $data)) {
            $settings->link_page_enabled = (bool) $data['enabled'];
        }
        if (array_key_exists('bio_links', $data)) {
            $settings->bio_links = array_values($data['bio_links'] ?? []);
        }
        $settings->save();

        return response()->json($this->state($settings->fresh()));
    }

    private function settingsFor(Request $request): BusinessStoreSettings
    {
        return BusinessStoreSettings::firstOrCreate(['business_id' => $request->user()->business_id]);
    }

    /**
     * Lo que necesita el POS: si está publicada, su dirección y la misma
     * vista que ve el público, para mostrar qué aparece solo.
     *
     * @return array<string, mixed>
     */
    private function state(BusinessStoreSettings $settings): array
    {
        $business = $settings->business;

        return [
            'enabled' => (bool) $settings->link_page_enabled,
            'published' => $this->linkPage->isPublished($business, $settings),
            'has_store' => $this->linkPage->hasOpenStore($business, $settings),
            'public_url' => $this->linkPage->publicUrl($business),
            'page' => $this->linkPage->publicPayload($business, $settings),
        ];
    }
}
