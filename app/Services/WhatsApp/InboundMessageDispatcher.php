<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessWhatsAppFlowReply;
use App\Jobs\ProcessWhatsAppInbound;
use App\Jobs\ProcessWhatsAppUnsupportedMessage;
use App\Support\ChannelPhone;
use Illuminate\Support\Facades\Cache;

/**
 * Parseo de un mensaje entrante de WhatsApp (formato Graph API de Meta) y
 * despacho al job correspondiente. Compartido por los dos caminos que
 * pueden traer un evento de Meta hasta este POS: el webhook directo
 * (WhatsappWebhookController, mientras el numero siga apuntando a Meta) y
 * el reenvio firmado de Nexolu Communications (NexoluCommsWebhookController,
 * una vez el numero se mueva alli) - ninguno de los dos interpreta el
 * mensaje por su cuenta, ambos delegan aca.
 */
class InboundMessageDispatcher
{
    private const UNSUPPORTED_TYPES = ['audio', 'image', 'video', 'document', 'sticker', 'location', 'contacts'];

    /**
     * @param  array<string, mixed>  $entries  el array `entry` completo del payload de Meta
     */
    public function dispatch(array $entries): void
    {
        foreach ($entries as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $message) {
                    $this->dispatchMessage($message);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function dispatchMessage(array $message): void
    {
        $wamid = $message['id'] ?? null;
        $from = $message['from'] ?? null;

        if ($wamid === null || $from === null) {
            return;
        }

        // Deduplicacion: si Meta reintenta (o si Nexolu Communications
        // reenvia dos veces por su propia falta de idempotencia todavia), el
        // mismo wamid no se procesa dos veces. Cache::add es atomico: false
        // si la clave ya existia.
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

        // Respuesta de un WhatsApp Flow (formulario nativo de confirmacion de
        // un borrador). response_json llega como STRING JSON, no como objeto.
        if ($type === 'interactive' && ($message['interactive']['type'] ?? null) === 'nfm_reply') {
            $raw = $message['interactive']['nfm_reply']['response_json'] ?? null;
            $data = is_string($raw) ? json_decode($raw, true) : null;

            if (is_array($data)) {
                ProcessWhatsAppFlowReply::dispatch($from, $data);
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

        if (in_array($type, self::UNSUPPORTED_TYPES, true)) {
            ProcessWhatsAppUnsupportedMessage::dispatch($from, $type);
        }
    }
}
