<?php

namespace App\Services\Ai\Contracts;

/**
 * Un tipo de insight embebido: que datos usa, cuanto vive y como se redacta.
 *
 * La regla que gobierna toda implementacion: los NUMEROS se calculan en el
 * servidor (gatherData) y se le pasan al modelo ya hechos. El modelo SOLO los
 * redacta, nunca los inventa ni los calcula. Un insight en pantalla parece una
 * afirmacion autoritativa del sistema - a diferencia del chat, el usuario no
 * pregunto ni puede replicar - asi que una cifra alucinada ahi es mas grave.
 */
interface AiInsightDefinition
{
    /** Identificador estable. Se guarda en ai_insights.tipo. snake_case. */
    public function type(): string;

    /**
     * Feature del negocio requerida, o null si aplica a todos.
     *
     * Un insight de ventas no le sirve a un negocio sin el modulo, y generarlo
     * seria gastar tokens en algo que no se puede mostrar.
     */
    public function requiredFeature(): ?string;

    /**
     * Minutos que vive el insight antes de regenerarse.
     *
     * Es el techo de gasto: con 180, un negocio gasta como mucho ~8 generaciones
     * al dia de este insight, se abra la pantalla las veces que se abra.
     */
    public function ttlMinutes(): int;

    /**
     * Cifras del negocio, ya calculadas. Es lo unico que vera el modelo.
     *
     * @return array<string, mixed>
     */
    public function gatherData(int $businessId): array;

    /**
     * Prompt de sistema: reglas de redaccion y de aterrizaje.
     *
     * Debe prohibir inventar cifras y cuestionar el sistema, igual que el
     * prompt del chat, y acotar el largo (un insight es una o dos frases, no
     * un parrafo).
     */
    public function systemPrompt(): string;

    /** Prompt de usuario: las cifras de gatherData vueltas texto para el modelo. */
    public function userPrompt(array $data): string;

    /**
     * Si con los datos actuales tiene sentido mostrar el insight.
     *
     * Un negocio sin ventas hoy no necesita un "panorama del dia": devolver
     * false evita gastar una generacion en un texto vacio de contenido.
     */
    public function isWorthShowing(array $data): bool;

    /**
     * Texto que se muestra cuando el negocio NO tiene acceso al add-on de IA.
     *
     * Es el muro contextual: en vez de esconder la tarjeta, se muestra que
     * ESTA pantalla podria tener una lectura con IA, con un boton para
     * activar. No se genera nada (sin llamada al modelo, sin costo): es una
     * frase fija que anuncia la capacidad.
     */
    public function teaser(): string;

    /**
     * Pregunta contextual con la que se abre el chat desde "Preguntar mas".
     *
     * Es la bisagra entre el insight embebido y el chat: la tarjeta muestra un
     * dato, y esta pregunta deja al chat listo para profundizar en ESE tema,
     * sin que el usuario tenga que pensar que escribir. Debe ser una pregunta
     * que las herramientas del chat si puedan responder.
     */
    public function suggestedQuestion(): string;
}
