<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente hacia POST /v1/completions del Nexolu IA Core: redaccion de una
 * sola pasada (system+user prompt propio, sin herramientas ni historial),
 * usado por AiInsightService para redactar los insights embebidos. Mismo
 * host/API key que AiChatService, endpoint distinto porque el trabajo es
 * distinto (una tarjeta cacheada, no un turno de chat con conversation_id).
 */
class AiCompletionService
{
    /**
     * @param  array<string, mixed>  $context  TenantContext ya resuelto del negocio
     * @return array{text: string, input_tokens: int, output_tokens: int, model: string, cost_micros: ?int}
     */
    public function complete(string $system, string $user, array $context, int $maxTokens = 400): array
    {
        $baseUrl = config('services.ia_core.base_url');
        $apiKey = config('services.ia_core.api_key');

        if (! $baseUrl || ! $apiKey) {
            throw new RuntimeException('El Asistente de IA no esta configurado (falta IA_CORE_BASE_URL o IA_CORE_API_KEY).');
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post(rtrim($baseUrl, '/').'/v1/completions', [
                    'system' => $system,
                    'user' => $user,
                    'context' => $context,
                    'max_tokens' => $maxTokens,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar al Asistente de IA.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException($response->json('detail') ?? 'El Asistente de IA no pudo redactar el insight.');
        }

        return $response->json();
    }
}
