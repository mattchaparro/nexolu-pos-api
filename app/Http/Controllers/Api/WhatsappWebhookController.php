<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppInbound;
use App\Jobs\ProcessWhatsAppUnsupportedMessage;
use App\Support\ChannelPhone;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de WhatsApp Cloud API (Meta). verify() responde el challenge que
 * Meta pide al configurar el endpoint; handle() recibe mensajes/eventos.
 *
 * handle() SIEMPRE responde 200 de inmediato: el trabajo real se hace en
 * cola. Si se demorara aqui, Meta daria la entrega por fallida y
 * reintentaria, duplicando el mensaje.
 */
class WhatsappWebhookController extends Controller
{
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
        foreach ($request->input('entry', []) as $entry) {
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

                foreach ($change['value']['messages'] ?? [] as $message) {
                    $this->dispatchInboundMessage($message);
                }
            }
        }

        return response('', 200);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function dispatchInboundMessage(array $message): void
    {
        $wamid = $message['id'] ?? null;
        $from = $message['from'] ?? null;

        if ($wamid === null || $from === null) {
            return;
        }

        // Deduplicacion: si Meta reintenta, el mismo wamid no se procesa dos
        // veces. Cache::add es atomico: false si la clave ya existia.
        if (! Cache::add("wa_msg:{$wamid}", true, now()->addHours(6))) {
            return;
        }

        $from = ChannelPhone::normalize($from) ?? $from;
        $type = $message['type'] ?? null;

        if ($type === 'text') {
            $text = $message['text']['body'] ?? null;
            if ($text !== null) {
                ProcessWhatsAppInbound::dispatch($from, $text, $wamid);
            }

            return;
        }

        // Toque en fila de lista: reenvia el titulo elegido como si el
        // usuario lo hubiera escrito -> mismo job de texto.
        if ($type === 'interactive' && ($message['interactive']['type'] ?? null) === 'list_reply') {
            $title = $message['interactive']['list_reply']['title'] ?? null;
            if ($title !== null) {
                ProcessWhatsAppInbound::dispatch($from, $title, $wamid);
            }

            return;
        }

        $unsupportedTypes = ['audio', 'image', 'video', 'document', 'sticker', 'location', 'contacts'];
        if (in_array($type, $unsupportedTypes, true)) {
            ProcessWhatsAppUnsupportedMessage::dispatch($from, $type);
        }
    }
}
