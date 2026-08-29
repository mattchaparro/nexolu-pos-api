<?php

namespace App\Mail\Transport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Transporte de correo que sale por el Nexolu Communications Core.
 *
 * Se hizo como TRANSPORTE y no reescribiendo cada envio a proposito: hay 19
 * puntos de envio y 13 Mailables en este repo. Cambiar `MAIL_MAILER=comms`
 * los manda todos por el Core sin tocar una sola clase, y `Mail::fake()`,
 * las colas y el listener que alimenta el historial de correos de SuperAdmin
 * (LogSentEmail, que escucha MessageSent) siguen funcionando igual.
 *
 * El WhatsApp del POS ya salia por el Core; el correo no. Esto cierra esa
 * inconsistencia: un solo servicio con las credenciales de los proveedores,
 * un solo lugar donde ver que se envio y cuanto costo.
 *
 * Los headers `X-Nexolu-Business-Id` y `X-Nexolu-Email-Type` que ya ponen
 * los Mailables mapean directo a `business_id` y `reference` del Core, asi
 * que el reporte de uso por negocio funciona sin tocar los Mailables.
 */
class CommsTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $to = $this->firstRecipient($email);
        if ($to === null) {
            return;
        }

        $payload = array_filter([
            'business_id' => $this->header($email, 'X-Nexolu-Business-Id'),
            'reference' => $this->header($email, 'X-Nexolu-Email-Type'),
            'channels' => ['email'],
            'to' => ['email' => $to],
            'subject' => $email->getSubject(),
            'html' => $email->getHtmlBody(),
            'text' => $email->getTextBody(),
        ], fn ($value) => $value !== null && $value !== []);

        try {
            $response = Http::withToken((string) config('services.comms_core.api_key'))
                ->timeout(15)
                ->baseUrl(rtrim((string) config('services.comms_core.base_url'), '/'))
                ->post('/v1/notifications/send', $payload);
        } catch (ConnectionException $e) {
            // No se relanza: un correo que no sale nunca debe tumbar la
            // operacion que lo disparo (una venta, un alta de empleado). Se
            // registra y sigue -- mismo criterio que ya usa el canal de
            // WhatsApp del Core.
            Log::warning('comms.email: no se pudo contactar al Communications Core', [
                'reference' => $payload['reference'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        if ($response->failed()) {
            Log::warning('comms.email: el Communications Core rechazo el envio', [
                'reference' => $payload['reference'] ?? null,
                'status' => $response->status(),
                'body' => $response->json('detail') ?? $response->body(),
            ]);
        }
    }

    /**
     * El Core acepta UN destinatario por canal. Los Mailables de este repo
     * mandan a uno solo; si algun dia se manda a varios, se toma el primero
     * y se avisa en vez de perder el resto en silencio.
     */
    private function firstRecipient(Email $email): ?string
    {
        $recipients = $email->getTo();
        if ($recipients === []) {
            Log::warning('comms.email: mensaje sin destinatario, se descarta');

            return null;
        }

        if (count($recipients) > 1) {
            Log::warning('comms.email: varios destinatarios, solo se envia al primero', [
                'count' => count($recipients),
            ]);
        }

        return $recipients[0]->getAddress();
    }

    private function header(Email $email, string $name): ?string
    {
        $header = $email->getHeaders()->get($name);

        return $header ? $header->getBodyAsString() : null;
    }

    public function __toString(): string
    {
        return 'comms';
    }
}
