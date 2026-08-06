<?php

namespace App\Services\Ai\Insights;

use App\Services\Ai\Contracts\AiInsightDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Lo que le debes a tus proveedores": el resumen con IA de compras
 * pendientes de pago.
 *
 * Simetrico a ReceivablesSummaryInsight (lo que a TI te deben) pero al reves:
 * lo que TU le debes a cada proveedor.
 */
class PayablesSummaryInsight implements AiInsightDefinition
{
    public function type(): string
    {
        return 'cuentas_por_pagar';
    }

    public function requiredFeature(): ?string
    {
        // No es un feature dedicado de cuentas por pagar: se mantiene igual
        // que el legacy, que tampoco lo tenia -- decision de gating existente,
        // no una omision al portar.
        return 'inventory';
    }

    public function ttlMinutes(): int
    {
        return 360;
    }

    public function gatherData(int $businessId): array
    {
        $now = CarbonImmutable::now();

        // Saldo por compra en subconsultas aparte (no un join directo a
        // lineas Y a abonos a la vez): con las dos tablas unidas a purchases,
        // cada fila de una se multiplica por las filas de la otra y el total
        // queda mal.
        $purchases = DB::table('purchases as p')
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->where('p.business_id', $businessId)
            ->where('p.payment_status', 'pending')
            ->selectRaw('COALESCE(s.name, "Sin proveedor") as supplier, p.purchased_at')
            ->selectRaw('(select coalesce(sum(pl.line_total_cop), 0) from purchase_lines pl where pl.purchase_id = p.id) as total')
            ->selectRaw('(select coalesce(sum(pp.amount), 0) from purchase_payments pp where pp.purchase_id = p.id) as paid')
            ->get()
            ->map(fn ($p) => [
                'supplier' => $p->supplier,
                'purchased_at' => $p->purchased_at,
                'balance' => round((float) $p->total - (float) $p->paid, 2),
            ])
            ->filter(fn ($p) => $p['balance'] > 0.009)
            ->values();

        $bySupplier = $purchases->groupBy('supplier')
            ->map(fn ($group, $supplier) => ['supplier' => $supplier, 'balance' => round($group->sum('balance'), 2)])
            ->sortByDesc('balance')
            ->values();

        $totalBalance = (float) $purchases->sum('balance');
        $biggest = $bySupplier->first();
        $oldest = $purchases->sortBy('purchased_at')->first();

        return [
            'total_balance' => round($totalBalance, 2),
            'supplier_count' => $bySupplier->count(),
            'biggest_supplier' => $biggest ? [
                'supplier' => $biggest['supplier'],
                'balance' => $biggest['balance'],
            ] : null,
            'oldest' => $oldest ? [
                'supplier' => $oldest['supplier'],
                'days' => (int) CarbonImmutable::parse($oldest['purchased_at'])->diffInDays($now),
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
        Eres el asistente de un negocio que usa el POS Nexolu. Redactas un resumen breve de las
        cuentas por pagar (lo que el negocio le debe a sus proveedores) que el dueno ve en la
        pantalla de compras.

        REGLAS:
        - Usa UNICAMENTE las cifras y nombres que te doy. Nunca inventes.
        - El valor es informativo, no una alarma: decir "le debes X a Y" es dato de gestion, no un
          problema. Si hay una compra pendiente vieja, mencionala como recordatorio util.
        - Nunca digas que el sistema falla.

        FORMATO:
        - Maximo 2 frases, en espanol, directo y claro. Sin jerga ni muletillas tipo "parce" o "listo pues": habla como alguien serio que ya reviso los datos, no como un amigo casual.
        - El dinero en pesos con separador de miles: $1.250.000.
        - Da la lectura. Sin introducciones tipo "Aqui esta".
        PROMPT;
    }

    public function userPrompt(array $data): string
    {
        $money = fn ($n) => '$'.number_format((float) $n, 0, ',', '.');

        $lines = [
            'Debes en total: '.$money($data['total_balance']).' a '.$data['supplier_count'].' proveedor(es).',
        ];

        if ($data['biggest_supplier']) {
            $lines[] = 'Al que mas le debes: '.$data['biggest_supplier']['supplier']
                .', '.$money($data['biggest_supplier']['balance']).'.';
        }

        if ($data['oldest'] && $data['oldest']['days'] > 0) {
            $lines[] = 'La compra pendiente mas vieja es de '.$data['oldest']['supplier']
                .', hace '.$data['oldest']['days'].' dias.';
        }

        return implode("\n", $lines)."\n\nRedacta las cuentas por pagar en 1 o 2 frases.";
    }

    public function teaser(): string
    {
        return 'El Asistente puede decirte cuanto les debes a tus proveedores.';
    }

    public function suggestedQuestion(): string
    {
        return '¿Cuanto le debo a cada proveedor?';
    }
}
