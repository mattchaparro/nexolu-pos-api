<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Contracts\ChannelOtpSender;
use Illuminate\Support\Facades\Log;

/**
 * Fallback para desarrollo/testing sin credenciales de WhatsApp: deja el
 * codigo en el log en vez de intentar un envio real.
 */
class LogChannelOtpSender implements ChannelOtpSender
{
    public function send(string $channel, string $externalId, string $code): void
    {
        Log::info('OTP de vinculacion de canal (emisor de log, sin envio real)', [
            'channel' => $channel,
            'external_id' => $externalId,
            'code' => $code,
        ]);
    }
}
