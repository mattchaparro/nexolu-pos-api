<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppUsageDaily;
use App\Services\Messaging\Contracts\MessagingChannel;
use App\Support\WhatsAppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente de la Graph API de WhatsApp Cloud - la implementacion de
 * MessagingChannel de hoy. Cada metodo publico arma un tipo de mensaje
 * distinto; post() es el unico punto que de verdad llama a Meta y registra
 * el consumo (solo lo que Meta acepto, para no inflar el costo con envios
 * rechazados).
 */
class WhatsAppCloudClient implements MessagingChannel
{
    private ?string $token;

    private ?string $phoneNumberId;

    private string $version;

    public function __construct(private WhatsAppUsageRecorder $usage)
    {
        $this->token = config('services.whatsapp.access_token');
        $this->phoneNumberId = config('services.whatsapp.phone_number_id');
        $this->version = (string) config('services.whatsapp.graph_version', 'v25.0');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->token) && ! empty($this->phoneNumberId);
    }

    /**
     * Texto libre. Solo se entrega dentro de la ventana de 24h desde el
     * ultimo mensaje del usuario - fuera de ella, usar sendTemplate().
     *
     * $businessId/$type no se usan aca (solo los lee LoggingMessagingChannel,
     * el decorator que envuelve esta clase) - ver MessagingChannel::sendText().
     */
    public function sendText(string $to, string $body, ?int $businessId = null, string $type = 'generico'): bool
    {
        return $this->post($to, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $body],
        ]);
    }

    /**
     * Mensaje de plantilla: via para mensajes iniciados por el negocio (OTP,
     * alertas), no dependen de la ventana de 24h.
     *
     * @param  list<array<string, mixed>>  $components
     */
    public function sendTemplate(
        string $to,
        string $name,
        string $languageCode,
        array $components = [],
        ?int $businessId = null,
        string $type = 'generico',
    ): bool {
        $template = ['name' => $name, 'language' => ['code' => $languageCode]];
        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->post($to, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]);
    }

    /**
     * Envia un WhatsApp Flow (formulario nativo) con datos prellenados. Se
     * usa para confirmar borradores editables del chat de IA: en vez de
     * dejar al usuario sin forma de tocar la confirmacion desde WhatsApp, se
     * le manda el Flow con los mismos datos ya cargados.
     *
     * @param  array<string, mixed>  $data  datos que precargan los campos del Flow
     */
    public function sendFlow(
        string $to,
        string $flowId,
        string $screen,
        string $bodyText,
        string $cta,
        array $data,
        string $flowToken,
        ?int $businessId = null,
        string $type = 'generico',
    ): bool {
        return $this->post($to, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'flow',
                'body' => ['text' => $bodyText],
                'action' => [
                    'name' => 'flow',
                    'parameters' => [
                        'flow_message_version' => '3',
                        'flow_token' => $flowToken,
                        'flow_id' => $flowId,
                        'flow_cta' => $cta,
                        'flow_action' => 'navigate',
                        'flow_action_payload' => [
                            'screen' => $screen,
                            'data' => $data,
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Documento por link (Meta descarga la URL, no se le mandan bytes). Solo
     * entrega dentro de la ventana de 24h - ver MessagingChannel::sendDocument().
     */
    public function sendDocument(
        string $to,
        string $documentUrl,
        string $filename,
        string $caption = '',
        ?int $businessId = null,
        string $type = 'generico',
    ): bool {
        $document = ['link' => $documentUrl, 'filename' => $filename];
        if ($caption !== '') {
            $document['caption'] = $caption;
        }

        return $this->post($to, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'document',
            'document' => $document,
        ]);
    }

    /**
     * Marca leido + activa el indicador de "escribiendo...". Mismo endpoint
     * de mensajes, con status:read + typing_indicator - no un endpoint aparte.
     */
    public function markAsReadWithTyping(string $to, string $messageId): bool
    {
        return $this->post($to, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
            'typing_indicator' => ['type' => 'text'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $to, array $payload): bool
    {
        if (! $this->isConfigured()) {
            $this->logSafe('warning', 'WhatsApp Cloud API: intento de envio sin credenciales', ['to' => $to]);

            return false;
        }

        $url = "https://graph.facebook.com/{$this->version}/{$this->phoneNumberId}/messages";

        try {
            $response = Http::withToken($this->token)->timeout(15)->post($url, $payload);

            if ($response->failed()) {
                $this->logSafe('warning', 'WhatsApp Cloud API: envio rechazado', [
                    'to' => $to,
                    'type' => $payload['type'] ?? null,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            $this->logSafe('info', 'WhatsApp Cloud API: mensaje aceptado', [
                'to' => $to,
                'type' => $payload['type'] ?? null,
                'wamid' => $response->json('messages.0.id'),
            ]);

            $this->recordUsage($to, $payload);

            return true;
        } catch (\Throwable $e) {
            $this->logSafe('error', 'WhatsApp Cloud API: error de red al enviar', ['to' => $to, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordUsage(string $to, array $payload): void
    {
        if (($payload['status'] ?? null) === 'read') {
            return; // acuse de lectura no es mensaje, no se cuenta
        }

        $category = ($payload['type'] ?? null) === 'template'
            ? WhatsAppSettings::categoryForTemplate($payload['template']['name'] ?? null)
            : WhatsAppUsageDaily::CATEGORY_SERVICE;

        $this->usage->record($category, $to);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logSafe(string $level, string $message, array $context): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable) {
            // El logging roto nunca debe quitarle al llamante el resultado real del envio.
        }
    }
}
