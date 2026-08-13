<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se agoto tanto el cupo mensual incluido como el balance de paquetes
 * comprados (ver App\Services\AiQuotaService::consumeMessage()). El mensaje
 * cambia segun quien la dispara: un admin puede comprar un paquete, un
 * empleado solo puede pedirselo al dueno.
 */
class AiQuotaExceededException extends RuntimeException
{
    public function __construct(bool $canPurchase = true)
    {
        parent::__construct($canPurchase
            ? 'Se agoto el cupo de mensajes de IA de este mes. Compra un paquete adicional desde Ajustes para seguir usando el Asistente.'
            : 'Se agoto el cupo de mensajes de IA de este mes. Pidele al dueno del negocio que compre un paquete adicional.');
    }
}
