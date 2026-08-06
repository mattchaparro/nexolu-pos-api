<?php

namespace App\Services\Ai\Insights;

use App\Services\Ai\Contracts\AiInsightDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Estado de tus fiados": el resumen con IA de la pantalla de fiados.
 *
 * Responde de un vistazo cuanto le deben, a quien conviene cobrarle primero y
 * que tan viejas estan las deudas. El valor esta en la accion: no es "te
 * deben X", es "cobrale a Juan, que lleva 45 dias".
 *
 * Agrupa por customer_key (no por el nombre crudo) para no fusionar dos
 * clientes distintos que comparten nombre.
 */
class ReceivablesSummaryInsight implements AiInsightDefinition
{
    public function type(): string
    {
        return 'fiados_resumen';
    }

    public function requiredFeature(): ?string
    {
        return 'receivables';
    }

    public function ttlMinutes(): int
    {
        return 360;
    }

    public function gatherData(int $businessId): array
    {
        $now = CarbonImmutable::now();

        $rows = DB::table('receivables')
            ->where('business_id', $businessId)
            ->where('status', '!=', 'paid')
            ->where('balance', '>', 0)
            ->groupBy('customer_key')
            ->selectRaw('MAX(customer_name) as customer')
            ->selectRaw('SUM(balance) as balance')
            ->selectRaw('MIN(created_at) as oldest')
            ->orderByDesc('balance')
            ->get();

        $totalBalance = (float) $rows->sum('balance');

        $biggest = $rows->first();

        $oldestRow = $rows->sortBy('oldest')->first();
        $oldestDays = $oldestRow
            ? (int) CarbonImmutable::parse($oldestRow->oldest)->diffInDays($now)
            : 0;

        return [
            'total_balance' => round($totalBalance, 2),
            'debtor_count' => $rows->count(),
            'biggest_debtor' => $biggest ? [
                'customer' => $biggest->customer ?: 'Sin nombre',
                'balance' => round((float) $biggest->balance, 2),
            ] : null,
            'oldest' => $oldestRow ? [
                'customer' => $oldestRow->customer ?: 'Sin nombre',
                'days' => $oldestDays,
            ] : null,
        ];
    }

    public function isWorthShowing(array $data): bool
    {
        return $data['total_balance'] > 0;
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        Eres el asistente de un negocio que usa el POS Nexolu. Redactas un resumen breve del estado
        de los fiados (lo que los clientes le deben al negocio) que el dueno ve al entrar a esa pantalla.

        REGLAS:
        - Usa UNICAMENTE las cifras y nombres que te doy. Nunca inventes.
        - El valor es ACCIONABLE: dile cuanto le deben en total y sugiere a quien cobrarle primero,
          usando al deudor mas grande o al fiado mas viejo. Una deuda vieja es la mas urgente.
        - Nunca digas que el sistema falla. Un saldo alto es informacion del negocio.
        - No inventes numeros de telefono ni datos de contacto.

        FORMATO:
        - Maximo 2 frases, en espanol, directo y claro. Sin jerga ni muletillas tipo "parce" o "listo pues": habla como alguien serio que ya reviso los datos, no como un amigo casual.
        - El dinero en pesos con separador de miles: $1.250.000.
        - Da la lectura y una sugerencia de cobro. Sin introducciones tipo "Aqui esta".
        PROMPT;
    }

    public function userPrompt(array $data): string
    {
        $money = fn ($n) => '$'.number_format((float) $n, 0, ',', '.');

        $lines = [
            'Te deben en total: '.$money($data['total_balance']).' entre '.$data['debtor_count'].' cliente(s).',
        ];

        if ($data['biggest_debtor']) {
            $lines[] = 'El que mas debe: '.$data['biggest_debtor']['customer']
                .' con '.$money($data['biggest_debtor']['balance']).'.';
        }

        if ($data['oldest'] && $data['oldest']['days'] > 0) {
            $lines[] = 'El fiado mas viejo es de '.$data['oldest']['customer']
                .', hace '.$data['oldest']['days'].' dias.';
        }

        return implode("\n", $lines)."\n\nRedacta el estado de los fiados en 1 o 2 frases, con una sugerencia de a quien cobrar.";
    }

    public function teaser(): string
    {
        return 'El Asistente puede decirte a quien cobrarle primero de tus fiados.';
    }

    public function suggestedQuestion(): string
    {
        return '¿A quien deberia cobrarle primero?';
    }
}
