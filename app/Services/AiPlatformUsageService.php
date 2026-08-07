<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Costo real de IA de esta app, vs GET /v1/platform/usage del Nexolu IA
 * Core - la unica llamada que usa la API key de PLATAFORMA
 * (NEXOLU_PLATFORM_API_KEY, no la simetrica de /v1/chat), porque ese
 * endpoint ve el gasto de TODAS las apps del Core, no solo el propio.
 *
 * Usado por PlatformFinanceService (dashboard de Finance de SuperAdmin, sin
 * cache: se calcula en vivo cada carga). Nunca lanza: un costo que no se
 * pudo consultar es 'desconocido', no un error que tumbe la pantalla - el
 * llamante decide como mostrarlo (ver 'ai_cost_available' en el summary).
 */
class AiPlatformUsageService
{
    private const APP_ID = 'pos';

    /** Costo real en USD de esta app en el rango, o null si no se pudo consultar. */
    public function costUsdForPeriod(string $dateFrom, string $dateTo): ?float
    {
        $baseUrl = config('services.ia_core.base_url');
        $apiKey = config('services.ia_core.platform_api_key');

        if (! $baseUrl || ! $apiKey) {
            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(15)
                ->get(rtrim($baseUrl, '/').'/v1/platform/usage', [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ]);
        } catch (\Throwable $e) {
            Log::warning('ai_platform_usage: no se pudo contactar al IA Core', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('ai_platform_usage: el IA Core rechazo la consulta', ['status' => $response->status()]);

            return null;
        }

        // Sin app_id en la query, el breakdown agrupa por app - se busca la
        // fila de esta app; sin uso todavia (negocio nuevo, IA Core recien
        // desplegado) es 0.0, no 'desconocido'.
        foreach ($response->json('breakdown') ?? [] as $row) {
            if (($row['key'] ?? null) === self::APP_ID) {
                return (float) ($row['cost_usd'] ?? 0);
            }
        }

        return 0.0;
    }
}
