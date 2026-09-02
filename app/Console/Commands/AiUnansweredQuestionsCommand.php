<?php

namespace App\Console\Commands;

use App\Models\AiUnansweredQuestion;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Lista las preguntas que el Asistente no supo responder.
 *
 * Es la lista de trabajo para ampliar el chat: cada fila es un dueño que quiso
 * saber algo y se fue sin el dato. Se agrupan por texto para que la misma
 * necesidad repetida por varios negocios se vea como una sola linea con su
 * conteo, que es lo que permite priorizar.
 *
 * Existe como comando y no como pantalla porque el panel de SuperAdmin todavia
 * no tiene donde ponerla (agregar un servicio exige tocar el BFF y el front),
 * y sin ningun lector la tabla se llena sin que nadie la mire - que es
 * exactamente como se perdio la señal en el legacy.
 */
class AiUnansweredQuestionsCommand extends Command
{
    protected $signature = 'ai:unanswered
        {--limit=40 : Cuantas preguntas distintas mostrar}
        {--all : Incluir tambien las ya marcadas como revisadas}';

    protected $description = 'Preguntas que el Asistente de IA no pudo responder, agrupadas y priorizadas';

    public function handle(): int
    {
        $rows = AiUnansweredQuestion::query()
            // Sin el scope de negocio: es un reporte de plataforma, no de un
            // negocio, y corre desde consola sin usuario autenticado.
            ->withoutGlobalScopes()
            ->when(! $this->option('all'), fn ($query) => $query->where('revisada', false))
            ->groupByRaw('LOWER(pregunta)')
            ->selectRaw('MAX(pregunta) as pregunta')
            ->selectRaw('COUNT(*) as veces')
            ->selectRaw('COUNT(DISTINCT business_id) as negocios')
            ->selectRaw('MAX(created_at) as ultima_vez')
            ->orderByDesc('veces')
            ->orderByDesc('ultima_vez')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No hay preguntas sin responder registradas.');

            return self::SUCCESS;
        }

        $this->table(
            ['Pregunta', 'Veces', 'Negocios', 'Ultima vez'],
            $rows->map(fn ($row) => [
                Str::limit((string) $row->pregunta, 90),
                (int) $row->veces,
                (int) $row->negocios,
                substr((string) $row->ultima_vez, 0, 16),
            ])->all()
        );

        $this->newLine();
        $this->comment('Ojo: aca tambien cae la charla suelta y las preguntas sobre el propio asistente,');
        $this->comment('que no son huecos de cobertura. Y antes de escribir una herramienta nueva por');
        $this->comment('una fila de esta lista, verifica que no exista ya: en los 24 registros del');
        $this->comment('legacy, casi todos TENIAN herramienta y fallaron por otra razon.');

        return self::SUCCESS;
    }
}
