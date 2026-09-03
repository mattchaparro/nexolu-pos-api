<?php

namespace App\Support;

/**
 * Canal de soporte de Nexolu hacia sus propios clientes (los negocios).
 *
 * Es WhatsApp y no un sistema de tickets a proposito: el modulo de tickets
 * existio en el legacy y no lo uso nadie nunca. El dueño de un local no abre
 * un ticket - escribe por WhatsApp, que es donde ya esta.
 *
 * El numero se lee primero de system_config (`billing.whatsapp_number`, la
 * misma clave que usaba el legacy) para poder cambiarlo sin desplegar, y cae
 * a config/env si no esta seteado. Antes vivia escrito a mano en dos clases
 * de Mail, que es como termina desincronizado.
 */
final class SupportContact
{
    public static function whatsappNumber(): string
    {
        $configured = (string) config('support.whatsapp_number');
        $stored = (string) (SystemConfigStore::get('billing.whatsapp_number') ?? '');

        // Si lo guardado en system_config no es un numero valido se cae al de
        // config en vez de devolver null: quedarse sin canal de soporte por un
        // dedazo en un campo de texto es peor que usar el numero de siempre.
        return ChannelPhone::normalize($stored) ?? ChannelPhone::normalize($configured) ?? $configured;
    }

    /** Enlace de WhatsApp con el mensaje ya escrito. */
    public static function whatsappUrl(string $message): string
    {
        return 'https://wa.me/'.self::whatsappNumber().'?text='.rawurlencode($message);
    }
}
