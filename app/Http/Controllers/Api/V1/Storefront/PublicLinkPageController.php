<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessStoreInteraction;
use App\Models\BusinessStoreSettings;
use App\Services\LinkPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * La página de enlaces pública (la de la bio). Sin auth y sin el middleware
 * de la tienda: existe aunque el negocio no tenga tienda online.
 *
 * Todo lo no publicado responde 404, igual que la tienda: un negocio
 * inexistente y uno que no activó su página tienen que ser indistinguibles,
 * o se podría listar quién es cliente de Nexolú probando slugs.
 */
class PublicLinkPageController extends Controller
{
    public function __construct(private LinkPageService $linkPage) {}

    public function show(string $slug): JsonResponse
    {
        [$business, $settings] = $this->resolve($slug);

        return response()->json($this->linkPage->publicPayload($business, $settings));
    }

    /**
     * El botón de WhatsApp pasa por aquí para contar el clic, como el de la
     * tienda. El número sale SIEMPRE del servidor: aceptarlo por parámetro
     * convertiría esto en un redirector abierto.
     */
    public function whatsapp(string $slug): RedirectResponse
    {
        [$business, $settings] = $this->resolve($slug);
        $phone = $this->linkPage->whatsappNumber($business, $settings);
        abort_if($phone === null, 404);

        BusinessStoreInteraction::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'type' => BusinessStoreInteraction::TYPE_WHATSAPP,
            'context' => 'bio',
        ]);

        return redirect()->away('https://wa.me/'.$phone.'?text='.rawurlencode('Hola, te escribo desde tu página de enlaces.'));
    }

    /** @return array{0: Business, 1: ?BusinessStoreSettings} */
    private function resolve(string $slug): array
    {
        $business = Business::query()->where('slug', $slug)->first();
        abort_if($business === null, 404);

        $settings = $this->linkPage->settingsFor($business);
        abort_unless($this->linkPage->isPublished($business, $settings), 404);

        return [$business, $settings];
    }
}
