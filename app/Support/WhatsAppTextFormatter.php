<?php

namespace App\Support;

/**
 * Traduce el markdown que devuelve el IA Core (pensado para el chat web) a
 * la sintaxis de formato de texto de WhatsApp.
 */
class WhatsAppTextFormatter
{
    public static function fromMarkdown(string $text): string
    {
        // Encabezados -> negrita.
        $text = preg_replace('/^\s{0,3}#{1,6}\s*(.+?)\s*$/m', '*$1*', $text) ?? $text;
        // **x**/__x__ -> *x*
        $text = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $text) ?? $text;
        $text = preg_replace('/__(.+?)__/s', '*$1*', $text) ?? $text;
        // ~~x~~ -> ~x~
        $text = preg_replace('/~~(.+?)~~/s', '~$1~', $text) ?? $text;
        // [texto](url) -> texto (url)
        $text = preg_replace('/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/', '$1 ($2)', $text) ?? $text;

        return $text;
    }
}
