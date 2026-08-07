<?php

namespace App\Services\Ai\Insights;

use App\Services\Ai\Contracts\AiInsightDefinition;
use Illuminate\Support\Facades\DB;

/**
 * La lectura con IA del historico de cierres de caja.
 *
 * El valor esta en la TENDENCIA: no un cierre suelto, sino si la caja viene
 * cuadrando o si hay faltantes recurrentes. Un patron de faltantes es la
 * senal temprana de un problema (robo hormiga, error de conteo, mal manejo).
 */
class CashClosingHistoryInsight implements AiInsightDefinition
{
    /** Cuantos cierres recientes se analizan para la tendencia. */
    private const WINDOW = 30;

    public function type(): string
    {
        return 'cierres_historico';
    }

    public function requiredFeature(): ?string
    {
        return 'cash_closing';
    }

    public function ttlMinutes(): int
    {
        return 720;
    }

    public function gatherData(int $businessId): array
    {
        $closings = DB::table('cash_closings')
            ->where('business_id', $businessId)
            ->orderByDesc('date')
            ->limit(self::WINDOW)
            ->get(['difference']);

        if ($closings->isEmpty()) {
            return ['total' => 0, 'balanced' => 0, 'short' => 0, 'total_shortfall' => 0.0];
        }

        $balanced = $closings->filter(fn ($c) => abs((float) ($c->difference ?? 0)) < 1)->count();
        $short = $closings->filter(fn ($c) => (float) ($c->difference ?? 0) <= -1);

        return [
            'total' => $closings->count(),
            'balanced' => $balanced,
            'short' => $short->count(),
            'total_shortfall' => round((float) $short->sum('difference'), 2),
        ];
    }

    public function isWorthShowing(array $data): bool
    {
        return $data['total'] > 0;
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        Eres el asistente de un negocio que usa el POS Nexolu. Redactas una lectura breve de la
        TENDENCIA de los cierres de caja recientes (no de uno solo).

        REGLAS:
        - Usa UNICAMENTE los datos que te doy. Nunca inventes.
        - Lo importante es el patron: si la caja viene cuadrando o si hay faltantes recurrentes. Un
          faltante repetido conviene revisarlo en la operacion (conteo, manejo de efectivo), NUNCA lo
          presentes como una falla del sistema.
        - Si casi todos los cierres cuadraron, dilo como algo positivo.

        FORMATO:
        - Maximo 2 frases, en espanol, directo y claro. Sin jerga ni muletillas tipo "parce" o "listo pues": habla como alguien serio que ya reviso los datos, no como un amigo casual.
        - El dinero en pesos con separador de miles: $1.250.000.
        - Da la lectura. Sin introducciones tipo "Aqui esta".
        PROMPT;
    }

    public function userPrompt(array $data): string
    {
        $money = fn ($n) => '$'.number_format(abs((float) $n), 0, ',', '.');

        $lines = [
            'De los ultimos '.$data['total'].' cierres, '.$data['balanced'].' cuadraron.',
        ];

        if ($data['short'] > 0) {
            $lines[] = $data['short'].' quedaron cortos, sumando '.$money($data['total_shortfall']).' de faltante en total.';
        } else {
            $lines[] = 'No hubo faltantes.';
        }

        return implode("\n", $lines)."\n\nRedacta la lectura de la tendencia de cierres en 1 o 2 frases.";
    }

    public function teaser(): string
    {
        return 'El Asistente puede decirte si tu caja viene cuadrando o hay faltantes recurrentes.';
    }

    public function suggestedQuestion(): string
    {
        return '¿Mi caja viene cuadrando en los ultimos cierres?';
    }
}
