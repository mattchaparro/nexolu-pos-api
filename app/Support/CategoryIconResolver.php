<?php

namespace App\Support;

/**
 * product_categories.icon en el schema compartido guarda nombres de Google
 * Material Icons (ej. 'local_bar', 'inventory_2' - ver el picker de categorias
 * del legacy en resources/js/utils/categoryIcons.js). El frontend nuevo
 * renderiza el icono de categoria tambien con Material Icons (ver
 * main.ts/style.css de nexolu-pos-front) - mismo vocabulario que el legacy a
 * proposito, sin traduccion de por medio: PrimeIcons (el sistema de iconos
 * del resto de la UI) no cubre iconografia de retail/comida, y reinventar un
 * mapeo propio solo suma imprecision donde el legacy ya tiene la respuesta
 * correcta resuelta.
 *
 * Esta clase solo pone un piso de seguridad (nunca vacio/null) - no traduce
 * formato.
 */
final class CategoryIconResolver
{
    public const DEFAULT_ICON = 'inventory_2';

    /**
     * @param  ?string  $rawIcon  Valor crudo de product_categories.icon.
     * @return string Nombre de Material Icon, nunca vacio.
     */
    public static function resolve(?string $rawIcon): string
    {
        $value = trim((string) $rawIcon);

        return $value !== '' ? $value : self::DEFAULT_ICON;
    }
}
