<?php

namespace App\Services\WhatsApp;

use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\WhatsApp\Contracts\ChannelOtpSender;

class WhatsAppOtpSender implements ChannelOtpSender
{
    public function __construct(private MessagingChannel $client) {}

    public function send(string $channel, string $externalId, string $code): void
    {
        $template = config('services.whatsapp.templates.otp');

        // Sin plantilla configurada: cae a texto libre (solo sirve dentro de
        // la ventana de 24h, no entrega OTP a un numero nuevo).
        if (empty($template['name'])) {
            $this->client->sendText(
                $externalId,
                "Tu codigo de vinculacion con Nexolú es {$code}. Vence en 5 minutos."
            );

            return;
        }

        // Plantilla de autenticacion: el codigo va en el body y, si tiene
        // boton de copiar, tambien en el boton (sub_type url, index 0).
        $components = [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
        ];

        if ($template['has_button'] ?? false) {
            $components[] = [
                'type' => 'button', 'sub_type' => 'url', 'index' => 0,
                'parameters' => [['type' => 'text', 'text' => $code]],
            ];
        }

        $this->client->sendTemplate($externalId, (string) $template['name'], (string) ($template['lang'] ?? 'es'), $components);
    }
}
