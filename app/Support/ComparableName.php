<?php

namespace App\Support;

/**
 * Normaliza un nombre escrito por una persona para poder compararlo.
 *
 * Quita tildes, mayusculas y puntuacion, que es lo que separa "Empanada de
 * Pollo" de "empanada de pollo" y de "empanada d pollo." sin que ninguna de
 * las tres sea un producto distinto.
 */
final class ComparableName
{
    private const ACCENTS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ü' => 'u', 'ñ' => 'n',
    ];

    public static function of(string $text): string
    {
        $withoutAccents = strtr(mb_strtolower(trim($text)), self::ACCENTS);
        $alphanumericOnly = preg_replace('/[^a-z0-9 ]/', '', $withoutAccents);

        // Separa numero y unidad que quedan pegados en el nombre del catalogo
        // ("500ml", "1.5l" tras quitar el punto) para que compare igual sea
        // que el usuario lo diga con espacio ("500 ml") o sin el. Bug real
        // del legacy: "Gaseosa Colombiana 500ml" no coincidia con "gaseosas
        // de 500 ml" porque "500" y "ml" nunca aparecian como palabras
        // sueltas en el nombre.
        $withBoundaries = preg_replace('/(?<=[0-9])(?=[a-z])|(?<=[a-z])(?=[0-9])/', ' ', $alphanumericOnly);

        return trim(preg_replace('/\s+/', ' ', $withBoundaries));
    }
}
