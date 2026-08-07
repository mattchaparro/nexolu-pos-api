<?php

namespace App\Listeners;

use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;

/**
 * Registra en `email_logs` TODO correo que la app envie, sin que cada
 * Mailable tenga que acordarse de hacerlo (asi el historial del panel de
 * SuperAdmin nunca queda incompleto por un call site que olvido loguear).
 *
 * business_id/type son opcionales: un Mailable los declara con headers
 * propios (X-Nexolu-Business-Id, X-Nexolu-Email-Type) si quiere que el
 * correo aparezca clasificado/filtrable; sin ellos, igual queda el registro
 * con destinatario y asunto, solo que sin ese contexto.
 *
 * Laravel no dispara un evento de fallo de envio (una excepcion en el
 * transport simplemente propaga) - por eso este listener solo cubre el
 * caso exitoso; un envio fallido nunca genera una fila 'sent' fantasma.
 */
class LogSentEmail
{
    public function handle(MessageSent $event): void
    {
        try {
            $message = $event->message;
            $to = $message->getTo();
            $toEmail = $to !== [] ? $to[0]->getAddress() : 'desconocido@sin-destinatario';

            $businessId = $this->headerValue($message, 'X-Nexolu-Business-Id');

            EmailLog::create([
                'business_id' => $businessId !== null ? (int) $businessId : null,
                'type' => $this->headerValue($message, 'X-Nexolu-Email-Type') ?? 'generico',
                'to_email' => $toEmail,
                'subject' => (string) $message->getSubject(),
                'status' => EmailLog::STATUS_SENT,
            ]);
        } catch (\Throwable $e) {
            // Nunca dejar que un fallo al loguear tumbe el envio real del correo.
            Log::warning('email_log.write_failed', ['error' => $e->getMessage()]);
        }
    }

    private function headerValue($message, string $name): ?string
    {
        $header = $message->getHeaders()->get($name);

        return $header?->getBodyAsString();
    }
}
