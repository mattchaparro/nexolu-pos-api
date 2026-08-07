<?php

namespace App\Services\Ai\Insights;

use App\Models\Expense;
use App\Services\Ai\Contracts\AiInsightDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "En que se te va la plata": el resumen con IA de la pantalla de gastos.
 *
 * Responde de un vistazo la pregunta que el dueno tiene al entrar a gastos:
 * en que se le esta yendo el dinero este mes y si va gastando mas o menos que
 * el mes pasado.
 */
class ExpensesSummaryInsight implements AiInsightDefinition
{
    public function type(): string
    {
        return 'gastos_resumen';
    }

    public function requiredFeature(): ?string
    {
        return 'expenses';
    }

    public function ttlMinutes(): int
    {
        return 360;
    }

    public function gatherData(int $businessId): array
    {
        $now = CarbonImmutable::now();
        $startOfMonth = $now->startOfMonth();
        $startOfLastMonth = $startOfMonth->subMonth();
        $endOfLastMonth = $startOfMonth->subSecond();

        $totalThisMonth = $this->totalBetween($businessId, $startOfMonth, $now);
        $totalLastMonth = $this->totalBetween($businessId, $startOfLastMonth, $endOfLastMonth);

        $byType = DB::table('expenses as e')
            ->leftJoin('expense_types as et', 'et.id', '=', 'e.type_id')
            ->where('e.business_id', $businessId)
            ->whereBetween('e.date', [$startOfMonth->toDateString(), $now->toDateString()])
            ->groupBy('e.type_id')
            ->selectRaw('COALESCE(MAX(et.name), "Sin tipo") as type')
            ->selectRaw('SUM(e.value) as total')
            ->orderByDesc('total')
            ->limit(3)
            ->get()
            ->map(fn ($row) => ['type' => (string) $row->type, 'total' => round((float) $row->total, 2)])
            ->all();

        return [
            'month' => $this->monthName($startOfMonth),
            'total_this_month' => round($totalThisMonth, 2),
            'total_last_month' => round($totalLastMonth, 2),
            'top_categories' => $byType,
        ];
    }

    public function isWorthShowing(array $data): bool
    {
        return $data['total_this_month'] > 0 || $data['total_last_month'] > 0;
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        Eres el asistente de un negocio que usa el POS Nexolu. Redactas un resumen breve de los
        gastos del mes que el dueno ve al entrar a la pantalla de Gastos.

        REGLAS:
        - Usa UNICAMENTE las cifras que te doy. Nunca inventes ni calcules numeros nuevos.
        - Di en que se esta yendo la plata (la categoria mas grande) y si va gastando mas o menos
          que el mes pasado, con criterio: el mes actual esta EN CURSO, asi que compararlo con el
          total del mes pasado completo puede ser enganoso si apenas empieza el mes; tenlo en cuenta.
        - Nunca digas ni insinues que el sistema falla o registra mal. Un gasto alto es informacion
          del negocio, no un error.
        - No juzgues moralmente los gastos; solo informa.

        FORMATO:
        - Maximo 2 frases, en espanol, directo y claro. Sin jerga ni muletillas tipo "parce" o "listo pues": habla como alguien serio que ya reviso los datos, no como un amigo casual.
        - El dinero en pesos con separador de miles: $1.250.000.
        - Da la lectura, no un listado. Sin introducciones tipo "Aqui esta".
        PROMPT;
    }

    public function userPrompt(array $data): string
    {
        $money = fn ($n) => '$'.number_format((float) $n, 0, ',', '.');

        $lines = [
            'Mes actual ('.$data['month'].', en curso): '.$money($data['total_this_month']).' en gastos.',
            'Mes pasado completo: '.$money($data['total_last_month']).'.',
        ];

        if ($data['top_categories'] !== []) {
            $categories = array_map(fn ($c) => $c['type'].' '.$money($c['total']), $data['top_categories']);
            $lines[] = 'Categorias con mas gasto este mes: '.implode(', ', $categories).'.';
        }

        return implode("\n", $lines)."\n\nRedacta el resumen de gastos en 1 o 2 frases.";
    }

    private function totalBetween(int $businessId, CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) Expense::query()
            ->where('business_id', $businessId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('value');
    }

    private function monthName(CarbonImmutable $date): string
    {
        return [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
            7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ][$date->month] ?? (string) $date->month;
    }

    public function teaser(): string
    {
        return 'El Asistente puede leerte en que se te esta yendo la plata este mes.';
    }

    public function suggestedQuestion(): string
    {
        return '¿En que estoy gastando mas este mes?';
    }
}
