<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El superadmin desactivo el Asistente de IA para este negocio
 * (Business::ai_chat_blocked, ver SuperAdmin\BusinessesController::toggleAiChatBlock()).
 * Extiende RuntimeException a proposito: los jobs de WhatsApp (ProcessWhatsAppInbound,
 * ProcessWhatsAppFlowReply) ya atrapan RuntimeException generico y responden
 * el mensaje tal cual por el mismo canal, sin necesitar un catch especifico.
 */
class AiChatBlockedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El Asistente de IA esta desactivado para tu negocio. Contacta a soporte.');
    }
}
