<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pregunta que el Asistente respondio SIN usar ninguna herramienta: o no
 * existe una que sirva, o el modelo no la encontro. Es la lista de trabajo
 * para ampliar el chat; sin ella un vacio de cobertura solo se descubre si
 * alguien manda una captura de pantalla.
 *
 * Los nombres de columna (`pregunta`, `respuesta`, `revisada`) son los del
 * schema compartido con legacy - no se traducen, la tabla ya existe en
 * produccion y el legacy la escribe con esos nombres.
 *
 * Ojo al leerla: tambien cae aqui la charla suelta ("hola", "cualquiera esta
 * ok") y las preguntas sobre el propio asistente ("que herramientas tienes"),
 * que no son huecos de cobertura.
 *
 * Y algo mas importante, visto en los 24 registros que dejo el legacy: casi
 * ninguno era una herramienta que faltara. "Compre 7 gaseosas", "pague un
 * recibo de luz por 25.000" o "cuanto he vendido desde que tengo el programa"
 * TENIAN herramienta en el legacy y aun asi quedaron sin responder, porque el
 * usuario tenia que elegir un agente y elegia el que no la llevaba. Antes de
 * escribir una herramienta nueva por una fila de esta tabla, verificar que no
 * exista ya.
 */
#[Fillable(['business_id', 'user_id', 'ai_conversation_id', 'pregunta', 'respuesta', 'revisada'])]
class AiUnansweredQuestion extends Model
{
    use BelongsToBusiness, HasFactory;

    protected function casts(): array
    {
        return [
            'revisada' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
