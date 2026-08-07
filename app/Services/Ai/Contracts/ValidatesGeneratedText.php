<?php

namespace App\Services\Ai\Contracts;

/**
 * Red de seguridad deterministica contra alucinaciones puntuales: el modelo
 * puede redactar algo que contradiga los datos que se le dieron, aunque el
 * prompt se los haya dado explicitos. No intenta corregir el texto - eso
 * seria adivinar de nuevo; solo avisa que no es confiable para que
 * AiInsightService lo descarte y no lo cachee.
 */
interface ValidatesGeneratedText
{
    /** @param  array<string, mixed>  $data  lo que devolvio gatherData(), lo mismo que redacto el modelo */
    public function isTextValid(string $text, array $data): bool;
}
