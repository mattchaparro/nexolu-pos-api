<?php

namespace App\Services\WhatsApp\Exceptions;

use RuntimeException;

/**
 * Mensaje apto para mostrar tal cual al usuario (numero invalido, codigo
 * vencido, demasiados intentos, etc.).
 */
class ChannelLinkException extends RuntimeException {}
