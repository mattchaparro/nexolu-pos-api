<?php

namespace App\Services\Ai\Contracts;

/**
 * Un insight que puede ofrecer una accion de un toque, no solo texto para
 * leer: la tarjeta abre el chat con el mensaje ya escrito, listo para
 * confirmar (el borrador con confirmacion humana hace el resto).
 */
interface HasSuggestedAction
{
    /**
     * @param  array<string, mixed>  $data  lo que devolvio gatherData()
     * @return array{label: string, message: string}|null null si con estos datos no hay accion que ofrecer
     */
    public function suggestedAction(array $data): ?array;
}
