<?php

namespace App\Jobs;

use App\Services\AiDraftService;
use App\Services\WhatsApp\IdentityResolver;
use App\Services\WhatsApp\WhatsAppCloudClient;
use App\Support\AiTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Procesa la respuesta de un WhatsApp Flow (formulario nativo) usado para
 * confirmar un borrador de escritura del chat de IA (ver ProcessWhatsAppInbound).
 *
 * El flow_token que se le puso al ENVIAR el Flow es directamente el id del
 * borrador en el IA Core (ese servicio ya sabe a que herramienta y negocio
 * pertenece - a diferencia de legacy, aqui no hace falta codificar el tipo
 * en el token). Meta lo re-emite tal cual en la respuesta.
 *
 * Generico a proposito: no sabe que campos trae cada tipo de Flow. Le pasa a
 * AiDraftService::confirm() todo lo que vino en la respuesta (menos
 * flow_token) como override de valores; el IA Core -via ToolGuard- descarta
 * lo que no aplica a esa herramienta antes de ejecutar.
 */
class ProcessWhatsAppFlowReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** @param  array<string, mixed>  $response  el response_json que devolvio el Flow */
    public function __construct(
        private readonly string $from,
        private readonly array $response,
    ) {}

    public function handle(IdentityResolver $resolver, AiDraftService $drafts, WhatsAppCloudClient $client): void
    {
        $user = $resolver->resolveUser('whatsapp', $this->from);

        // No deberia pasar: solo un numero vinculado pudo haber recibido el Flow.
        if ($user === null) {
            return;
        }

        $draftId = trim((string) ($this->response['flow_token'] ?? ''));
        if ($draftId === '') {
            Log::warning('whatsapp.flow: flow_token vacio', ['from' => $this->from]);

            return;
        }

        $values = collect($this->response)
            ->except('flow_token')
            ->filter(fn ($v) => $v !== null)
            ->all();

        try {
            $result = $drafts->confirm($draftId, AiTenantContext::forUser($user), $values);
        } catch (RuntimeException $e) {
            $client->sendText($this->from, 'No pude registrarlo. Intenta de nuevo.');
            Log::error('whatsapp.flow: fallo al contactar al Asistente de IA', [
                'draft_id' => $draftId, 'from' => $this->from, 'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($result->status() === 404) {
            $client->sendText($this->from, 'No encontre ese borrador. Puede que ya haya expirado.');

            return;
        }

        if ($result->status() === 409) {
            $client->sendText($this->from, 'Esto ya se habia registrado.');

            return;
        }

        if ($result->failed()) {
            $client->sendText($this->from, (string) ($result->json('detail') ?? 'No pude registrarlo. Intenta de nuevo.'));
            Log::warning('whatsapp.flow: el IA Core rechazo la confirmacion', [
                'draft_id' => $draftId, 'from' => $this->from, 'status' => $result->status(), 'body' => $result->body(),
            ]);

            return;
        }

        $client->sendText($this->from, '✅ Registrado.');
    }
}
