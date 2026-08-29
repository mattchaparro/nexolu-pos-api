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
        if ($channels === [] || $to === []) {
            return false;
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

            return false;
        }

        if ($response->failed()) {
            Log::warning('comms.notify: el Communications Core rechazo el envio', [
                'reference' => $reference,
                'channels' => $channels,
                'status' => $response->status(),
                'body' => $response->json('detail') ?? $response->body(),
            ]);

            return false;
        }

        return true;
    }
}
