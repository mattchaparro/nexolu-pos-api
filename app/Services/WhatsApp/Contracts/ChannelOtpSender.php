<?php

namespace App\Services\WhatsApp\Contracts;

interface ChannelOtpSender
{
    /**
     * @param  string  $channel  'whatsapp', etc.
     * @param  string  $externalId  telefono destino, digitos con codigo de pais
     * @param  string  $code  el codigo de 6 digitos en claro
     */
    public function send(string $channel, string $externalId, string $code): void;
}
