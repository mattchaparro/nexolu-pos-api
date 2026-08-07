<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\InboundMessageDispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de WhatsApp Cloud API (Meta). verify() responde el challenge que
 * Meta pide al configurar el endpoint; handle() recibe mensajes/eventos.
 *
 * handle() SIEMPRE responde 200 de inmediato: el trabajo real se hace en
 * cola. Si se demorara aqui, Meta daria la entrega por fallida y
 * reintentaria, duplicando el mensaje. El parseo del mensaje en si vive en
 * InboundMessageDispatcher, compartido con NexoluCommsWebhookController.
 */
class WhatsappWebhookController extends Controller
{
    public function __construct(private InboundMessageDispatcher $dispatcher) {}

    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode');
        $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            return response((string) $challenge, 200);
        }

        Log::warning('whatsapp.webhook: verificacion fallida', ['ip' => $request->ip()]);

        return response('Forbidden', 403);
    }

    public function handle(Request $request): Response
    {
        $entries = $request->input('entry', []);

        foreach ($entries as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    if (($status['status'] ?? null) === 'failed') {
                        Log::warning('whatsapp.webhook: entrega fallida', [
                            'wamid' => $status['id'] ?? null,
                            'recipient' => $status['recipient_id'] ?? null,
                            'errors' => $status['errors'] ?? null,
                        ]);
                    }
                }
            }
        }

        $this->dispatcher->dispatch($entries);

        return response('', 200);
    }
}
