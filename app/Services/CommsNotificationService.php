<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envio multicanal por el Nexolu Communications Core.
 *
 * Existe para el caso que un transporte de correo no puede cubrir: mandar
 * WhatsApp Y correo en UNA sola transaccion. Con dos llamadas separadas, si
 * una sale y la otra no, el comprador recibe la mitad del aviso y el Core
 * las cuenta como envios sueltos que nadie relaciona.
 *
 * `App\Mail\Transport\CommsTransport` usa este mismo metodo con un solo
 * canal, para que exista un unico lugar que sepa la forma del payload.
 */
class CommsNotificationService
{
    public function isConfigured(): bool
    {
        return filled(config('services.comms_core.api_key')) && filled(config('services.comms_core.base_url'));
    }

    /**
     * @param  list<string>  $channels  p.ej. ['whatsapp', 'email']
     * @param  array<string, string>  $to  destinatario por canal
     * @param  array<string, mixed>|null  $whatsappTemplate  {name, language, components}
     * @return bool si el Core acepto la notificacion
     */
    public function send(
        array $channels,
        array $to,
        ?string $subject = null,
        ?string $html = null,
        ?string $text = null,
        ?array $whatsappTemplate = null,
        ?int $businessId = null,
        ?string $reference = null,
    ): bool {
        return $this->dispatch(
            $channels, $to, $subject, $html, $text, $whatsappTemplate, $businessId, $reference,
        ) !== [];
    }

    /**
     * Igual que send(), pero devuelve QUE paso en cada canal.
     *
     * El Core responde por canal y nunca tumba uno por culpa de otro: correo
     * puede salir y WhatsApp fallar en la misma notificacion. Quien solo
     * necesita saber si algo salio usa send(); quien tiene que MOSTRARLE al
     * usuario que se entrego y que no -- una nota al comprador -- necesita el
     * detalle, incluido el motivo del fallo.
     *
     * @param  list<string>  $channels
     * @param  array<string, string>  $to
     * @param  array<string, mixed>|null  $whatsappTemplate
     * @return array<string, array{status: string, error: string|null}> por canal; vacio si no se pudo ni preguntar
     */
    public function dispatch(
        array $channels,
        array $to,
        ?string $subject = null,
        ?string $html = null,
        ?string $text = null,
        ?array $whatsappTemplate = null,
        ?int $businessId = null,
        ?string $reference = null,
    ): array {
        if ($channels === [] || $to === []) {
            return [];
        }

        $payload = array_filter([
            'business_id' => $businessId !== null ? (string) $businessId : null,
            'reference' => $reference,
            'channels' => $channels,
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'whatsapp_template' => $whatsappTemplate,
        ], fn ($value) => $value !== null && $value !== []);

        try {
            $response = Http::withToken((string) config('services.comms_core.api_key'))
                ->timeout(15)
                ->baseUrl(rtrim((string) config('services.comms_core.base_url'), '/'))
                ->post('/v1/notifications/send', $payload);
        } catch (ConnectionException $e) {
            // Nunca se relanza: un aviso que no sale no puede tumbar la
            // operacion que lo disparo.
            Log::warning('comms.notify: no se pudo contactar al Communications Core', [
                'reference' => $reference,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        if ($response->failed()) {
            Log::warning('comms.notify: el Communications Core rechazo el envio', [
                'reference' => $reference,
                'channels' => $channels,
                'status' => $response->status(),
                'body' => $response->json('detail') ?? $response->body(),
            ]);

            return [];
        }

        $porCanal = [];
        foreach ($response->json('results') ?? [] as $resultado) {
            $canal = $resultado['channel'] ?? null;
            if ($canal === null) {
                continue;
            }

            $porCanal[$canal] = [
                'status' => (string) ($resultado['status'] ?? 'sent'),
                'error' => $resultado['error'] ?? null,
            ];
        }

        // El Core acepto, pero es una version que no detalla por canal: no hay
        // nada que reportar salvo que no fallo.
        return $porCanal !== [] ? $porCanal : array_fill_keys($channels, ['status' => 'sent', 'error' => null]);
    }
}
