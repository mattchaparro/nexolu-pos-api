<?php

namespace App\Http\Resources\Api\V1\Storefront;

use App\Models\BusinessStoreImage;
use App\Models\BusinessStoreSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            // Cuanto se demora en llegar, en palabras del comerciante: no se
            // puede calcular (depende de ciudad, transportador y operacion).
            'delivery_estimate' => $this->delivery_estimate,
            'min_order_amount' => (float) $this->min_order_amount,
            'pickup_enabled' => (bool) $this->pickup_enabled,
            // Si el comprador va a pagar en la pasarela o a coordinar el
            // pago con la tienda. Solo el hecho, nunca con que proveedor:
            // eso es informacion del comercio, no del comprador.
            'accepts_online_payment' => $this->business?->activePaymentGateway() !== null,
            'terms' => $this->terms,

            // El home: la lista ordenada de bloques, ya con las imagenes
            // resueltas a URL. La tienda solo pinta; no busca nada.
            'home_blocks' => $this->resolvedBlocks(),

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

    /**
     * Los bloques listos para pintar: sin los apagados, sin los vacios, y
     * con `image_id` ya convertido a URL.
     *
     * Se resuelve aca y no en la tienda porque el storefront no tiene -- ni
     * debe tener -- forma de consultar la biblioteca de imagenes de un
     * comercio.
     *
     * @return list<array<string, mixed>>
     */
    private function resolvedBlocks(): array
    {
        $blocks = $this->home_blocks ?? [];
        if ($blocks === []) {
            return [];
        }

        $urls = $this->imageUrls($blocks);
        $resueltos = [];

        foreach ($blocks as $block) {
            if (($block['enabled'] ?? true) === false) {
                continue;
            }

            // Cualquier clave que termine en `image_id` se resuelve igual:
            // `image_id` -> `image_url`, `before_image_id` -> `before_image_url`.
            // Asi un bloque nuevo con dos imagenes (antes/despues) no obliga a
            // tocar este metodo.
            foreach (array_keys($block) as $key) {
                if (! is_string($key) || ! str_ends_with($key, 'image_id')) {
                    continue;
                }

                $destino = substr($key, 0, -2).'url';
                $block[$destino] = $urls[$block[$key]]['url'] ?? null;
                unset($block[$key]);
            }

            // Imagenes dentro de una lista (la reticula del bento).
            if (isset($block['items']) && is_array($block['items'])) {
                $block['items'] = array_map(function ($item) use ($urls) {
                    if (is_array($item) && array_key_exists('image_id', $item)) {
                        $item['image_url'] = $urls[$item['image_id']]['url'] ?? null;
                        unset($item['image_id']);
                    }

                    return $item;
                }, $block['items']);
            }

            if (array_key_exists('image_ids', $block)) {
                // Una imagen borrada de la biblioteca simplemente desaparece
                // del bloque, en vez de dejar un hueco roto en la galeria.
                $block['images'] = array_values(array_filter(array_map(
                    fn ($id) => $urls[$id] ?? null,
                    $block['image_ids'] ?? [],
                )));
                unset($block['image_ids']);
            }

            // `image_path` es de los bloques migrados desde las ranuras
            // viejas (hero/story), que guardaban la ruta directa.
            if (filled($block['image_path'] ?? null)) {
                $block['image_url'] = Storage::disk($this->disk ?: 'public')->url($block['image_path']);
            }
            unset($block['image_path'], $block['enabled']);

            $resueltos[] = $block;
        }

        return $resueltos;
    }

    /**
     * Todas las imagenes que referencian los bloques, en UNA consulta.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array<int, array{url: ?string, thumbnail_url: ?string, alt: ?string}>
     */
    private function imageUrls(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            foreach ($block as $key => $value) {
                // `image_id`, `before_image_id`, `after_image_id`...
                if (is_string($key) && str_ends_with($key, 'image_id') && $value !== null) {
                    $ids[] = (int) $value;
                }
            }

            foreach ($block['image_ids'] ?? [] as $id) {
                $ids[] = (int) $id;
            }

            // Las de la reticula del bento, que van dentro de cada item.
            foreach ($block['items'] ?? [] as $item) {
                if (is_array($item) && isset($item['image_id'])) {
                    $ids[] = (int) $item['image_id'];
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        return BusinessStoreImage::withoutGlobalScopes()
            ->where('business_id', $this->business_id)
            ->whereIn('id', array_unique($ids))
            ->get()
            ->mapWithKeys(fn (BusinessStoreImage $image) => [$image->id => [
                'url' => $image->url(),
                'thumbnail_url' => $image->thumbnailUrl(),
                'alt' => $image->alt,
            ]])
            ->all();
    }
}
