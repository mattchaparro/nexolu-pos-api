<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * Ojo al leerla: tambien cae aqui la charla suelta ("hola", "gracias") y las
 * preguntas sobre el propio asistente ("que sabes hacer"), que no son huecos
 * de cobertura. En los datos reales del legacy, 2 de 5 registros eran eso.
 * Se filtra a ojo al revisar; preferible ese ruido a perder la señal.
 */
#[Fillable(['business_id', 'user_id', 'ai_conversation_id', 'pregunta', 'respuesta', 'revisada'])]
class AiUnansweredQuestion extends Model
{
    use BelongsToBusiness;

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
