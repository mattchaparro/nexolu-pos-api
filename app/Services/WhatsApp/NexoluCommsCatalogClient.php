<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente del modulo de catalogo de Nexolu Connect (nexolu-comms-api,
 * POST /v1/catalog/*). Separado de MessagingChannel a proposito: el
 * catalogo SOLO existe en Connect - no hay equivalente "whatsapp_direct"
 * que alternar por driver, asi que el sync va a Connect sin importar como
 * salga la mensajeria. Misma base_url y api_key de services.comms_core.
 *
 * Nunca lanza hacia el caller: un sync de catalogo que falla no puede
 * tumbar el guardado de un producto ni un job de inventario - devuelve
 * null/false y deja el detalle en el log. Connect guarda el estado por
 * item (content_hash, pending/synced/error) y el panel lo muestra.
 */
class NexoluCommsCatalogClient
{
    public function isConfigured(): bool
    {
        return (string) config('services.comms_core.api_key') !== ''
            && (string) config('services.comms_core.base_url') !== '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $items  ya normalizados (retailer_id, title, price "9000 COP", ...)
     * @param  array<int, string>  $deletes  retailer_ids a retirar del catalogo
     * @return array<string, mixed>|null la respuesta de Connect (sent/skipped/deleted/handle/immediate_errors) o null si fallo
     */
    public function sync(array $items, array $deletes = []): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->client()->post('/v1/catalog/sync', [
                'items' => $items,
                'deletes' => $deletes,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('whatsapp_catalog.sync: sin conexion con Connect', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('whatsapp_catalog.sync: Connect rechazo el lote', [
                'status' => $response->status(),
                'detail' => $response->json('detail'),
            ]);

            return null;
        }

        return $response->json();
    }

    /**
     * Resuelve los items `pending` del catalogo contra Meta
     * (check_batch_request_status del lado de Connect).
     *
     * @return array<string, int>|null {synced, errors, still_pending} o null si fallo
     */
    public function check(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->client()->post('/v1/catalog/check', []);
        } catch (ConnectionException $e) {
            Log::warning('whatsapp_catalog.check: sin conexion con Connect', ['error' => $e->getMessage()]);

            return null;
        }

        return $response->failed() ? null : $response->json();
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('services.comms_core.api_key'))
            ->timeout(30)
            ->acceptJson()
            ->baseUrl(rtrim((string) config('services.comms_core.base_url'), '/'));
    }
}
