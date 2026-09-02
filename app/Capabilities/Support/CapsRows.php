<?php

namespace App\Capabilities\Support;

/**
 * Tope de filas que una capacidad puede devolverle al modelo.
 *
 * No es paranoia de rendimiento: cada fila viaja al proveedor de IA como
 * texto y se paga por token. Un catalogo de 3.000 productos en una sola
 * respuesta cuesta plata y ademas empeora la respuesta (el modelo se pierde).
 * Mismo valor que ToolGuard.MAX_ROWS del lado del IA Core.
 */
trait CapsRows
{
    private const MAX_ROWS = 100;

    /**
     * @param  list<mixed>  $rows
     * @return list<mixed>
     */
    private function capRows(array $rows): array
    {
        return array_slice($rows, 0, self::MAX_ROWS);
    }
}
