<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Unico cliente saliente hacia el Nexolu IA Core (servicio Python aparte,
 * repo Nexolu-IA-Core): arma la llamada a POST /v1/chat con la misma API key
 * que ese servicio usa para llamar de vuelta al despacho de herramientas
 * (ver App\Http\Controllers\Api\AiToolInvokeController) - es una unica
 * credencial simetrica por aplicacion, no dos.
 */
class AiChatService
{
    /**
     * @param  array<string, mixed>  $context  TenantContext ya resuelto del usuario autenticado
     * @return array<string, mixed>
     */
    public function send(string $agent, string $message, array $context, ?string $conversationId = null): array
    {
        $baseUrl = config('services.ia_core.base_url');
        $apiKey = config('services.ia_core.api_key');

        if (! $baseUrl || ! $apiKey) {
            throw new RuntimeException('El Asistente de IA no esta configurado (falta IA_CORE_BASE_URL o IA_CORE_API_KEY).');
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post(rtrim($baseUrl, '/').'/v1/chat', [
                    'agent' => $agent,
                    'message' => $message,
                    'context' => $context,
                    'conversation_id' => $conversationId,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar al Asistente de IA.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException($response->json('detail') ?? 'El Asistente de IA no pudo procesar el mensaje.');
        }

        return $response->json();
    }

    /**
     * Variante streaming de send(): mismo body, pero contra /v1/chat/stream
     * (Server-Sent Events). Guzzle con `stream => true` devuelve apenas
     * llegan los headers, sin esperar el body completo - por eso failed()
     * ya sirve para detectar un rechazo previo a la apertura del stream
     * (agente/app desconocido) sin tocar el body. El body PSR se devuelve
     * todavia abierto: quien llama es responsable de leerlo/streamearlo.
     *
     * @param  array<string, mixed>  $context  TenantContext ya resuelto del usuario autenticado
     */
    public function stream(string $agent, string $message, array $context, ?string $conversationId = null): Response
    {
        $baseUrl = config('services.ia_core.base_url');
        $apiKey = config('services.ia_core.api_key');

        if (! $baseUrl || ! $apiKey) {
            throw new RuntimeException('El Asistente de IA no esta configurado (falta IA_CORE_BASE_URL o IA_CORE_API_KEY).');
        }

        try {
            $response = Http::withToken($apiKey)
                ->withOptions(['stream' => true])
                ->timeout(120)
                ->post(rtrim($baseUrl, '/').'/v1/chat/stream', [
                    'agent' => $agent,
                    'message' => $message,
                    'context' => $context,
                    'conversation_id' => $conversationId,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar al Asistente de IA.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException($response->json('detail') ?? 'El Asistente de IA no pudo procesar el mensaje.');
        }

        return $response;
    }
}
