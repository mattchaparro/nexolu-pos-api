<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El plan del negocio vencio (Business::hasAccess() es false): el Asistente
 * de IA es parte del plan, asi que se corta igual que cualquier otro modulo
 * pago. Extiende RuntimeException a proposito, mismo motivo que
 * AiChatBlockedException: los jobs de WhatsApp ya atrapan RuntimeException
 * generico y responden el mensaje tal cual por el mismo canal.
 */
class AiSubscriptionExpiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tu plan vencio. Renueva tu suscripcion para seguir usando el Asistente de IA.');
    }
}
