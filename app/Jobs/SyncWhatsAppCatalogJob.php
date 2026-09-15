<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\Product;
use App\Services\WhatsApp\NexoluCommsCatalogClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Manda el catalogo publicable de UN negocio a Nexolu Connect
 * (POST /v1/catalog/sync), que lo traduce al items_batch oficial de Meta.
 *
 * Manda SIEMPRE el lote completo de productos con available_on_whatsapp:
 * Connect ya guarda un content_hash por item y no re-envia lo que no
 * cambio, asi que el costo real de un lote completo es "solo lo que
 * cambio" - y a cambio el job no necesita saber que cambio (el debounce
 * de dispatchDebounced() colapsa rafagas de guardados en una corrida).
 *
 * Productos sin imagen quedan FUERA del lote (el catalogo de Meta exige
 * image_link publica); se loguea cuantos, y el panel de Connect muestra lo
 * que si entro.
 */
class SyncWhatsAppCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    private const DEBOUNCE_SECONDS = 10;

    public function __construct(public readonly int $businessId) {}

    /**
     * Un solo job por negocio por ventana de DEBOUNCE_SECONDS: guardar 20
     * productos seguidos (o un import) dispara UNA corrida. Cache::add es
     * atomico - mismo patron que la deduplicacion de wamid del dispatcher.
     */
    public static function dispatchDebounced(int $businessId): void
    {
        if (Cache::add("wa_catalog_sync:{$businessId}", true, self::DEBOUNCE_SECONDS)) {
            self::dispatch($businessId)->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));
        }
    }

    public function handle(NexoluCommsCatalogClient $catalog): void
    {
        if (! $catalog->isConfigured()) {
            return;
        }

        $business = Business::find($this->businessId);
        if ($business === null) {
            return;
        }

        $products = Product::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->where('available_on_whatsapp', true)
            ->get();

        $items = [];
        $sinImagen = 0;

        foreach ($products as $product) {
            $item = self::normalize($product);
            if ($item === null) {
                $sinImagen++;

                continue;
            }
            $items[] = $item;
        }

        if ($sinImagen > 0) {
            Log::info('whatsapp_catalog.sync: productos sin imagen quedaron fuera', [
                'business_id' => $business->id,
                'sin_imagen' => $sinImagen,
            ]);
        }

        if ($items === []) {
            return;
        }

        $result = $catalog->sync($items);

        if ($result !== null) {
            Log::info('whatsapp_catalog.sync', [
                'business_id' => $business->id,
                'sent' => $result['sent'] ?? null,
                'skipped' => $result['skipped'] ?? null,
                'immediate_errors' => $result['immediate_errors'] ?? [],
            ]);
        }
    }

    /**
     * Un producto en el shape que espera /v1/catalog/sync de Connect (que a
     * su vez es el `data` del items_batch oficial). null = no publicable
     * (sin imagen).
     *
     * @return array<string, mixed>|null
     */
    public static function normalize(Product $product): ?array
    {
        $image = self::publicImageUrl($product);
        if ($image === null) {
            return null;
        }

        // Disponibilidad segun lo que el POS sabe: sin control de stock (o
        // servicios) siempre disponible; con control, segun el stock crudo.
        // Meta ademas remueve de los carritos lo que quede "out of stock".
        $available = ! $product->track_stock || (float) $product->stock > 0;

        $item = [
            'retailer_id' => $product->whatsappRetailerId(),
            'title' => $product->name,
            // Formato oficial del items_batch: "<monto> <moneda>". COP no
            // usa decimales.
            'price' => ((int) round((float) $product->price)).' COP',
            'availability' => $available ? 'in stock' : 'out of stock',
            'image_link' => $image,
        ];

        if ($product->description) {
            $item['description'] = $product->description;
        }

        return $item;
    }

    /**
     * La URL publica y ESTABLE de la imagen (Meta la cachea: no sirve una
     * firmada con vencimiento como la de los recibos). Si `image` ya es una
     * URL absoluta se publica tal cual; si es una ruta relativa, la ruta
     * publica del POS la sirve/redirige.
     */
    private static function publicImageUrl(Product $product): ?string
    {
        $image = trim((string) $product->image);
        if ($image === '') {
            return null;
        }

        if (str_starts_with($image, 'https://') || str_starts_with($image, 'http://')) {
            return $image;
        }

        return URL::route('products.public-image', ['product' => $product->id]);
    }
}
