<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente hacia POST /v1/drafts/{id}/confirm y /discard del Nexolu IA Core:
 * el unico camino por el que un borrador de escritura del chat (o de un
 * WhatsApp Flow, ver ProcessWhatsAppFlowReply) llega a ejecutarse de verdad.
 * El modelo nunca escribe directo - solo crea el borrador, y esto es lo que
 * dispara la confirmacion humana.
 *
 * Devuelve la Response cruda (no lanza en 4xx/5xx propios del Core, como 404
 * "no encontrado" o 409 "ya confirmado"): quien llama decide como traducir
 * esos codigos - el proxy HTTP los reenvia tal cual, el job de WhatsApp los
 * traduce a un mensaje de texto.
 */
class AiDraftService
{
    /**
     * @param  array<string, mixed>  $context  TenantContext ya resuelto del usuario autenticado
     * @param  array<string, mixed>|null  $values  valores editados, o null para usar el payload original del borrador
     */
    public function confirm(string $draftId, array $context, ?array $values = null): Response
    {
        return $this->post("/v1/drafts/{$draftId}/confirm", $context, $values);
    }

    /** @param  array<string, mixed>  $context */
    public function discard(string $draftId, array $context): Response
    {
        return $this->post("/v1/drafts/{$draftId}/discard", $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $values
     */
    private function post(string $path, array $context, ?array $values = null): Response
    {
        $baseUrl = config('services.ia_core.base_url');
        $apiKey = config('services.ia_core.api_key');

        if (! $baseUrl || ! $apiKey) {
            throw new RuntimeException('El Asistente de IA no esta configurado (falta IA_CORE_BASE_URL o IA_CORE_API_KEY).');
        }

        $payload = ['context' => $context];
        if ($values !== null) {
            $payload['values'] = $values;
        }

        try {
            return Http::withToken($apiKey)->timeout(30)->post(rtrim($baseUrl, '/').$path, $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo contactar al Asistente de IA.', previous: $e);
        }
    }
}
