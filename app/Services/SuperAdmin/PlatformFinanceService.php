<?php

namespace App\Services\SuperAdmin;

use App\Models\Business;
use App\Models\SaasSubscriptionPayment;
use Illuminate\Support\Carbon;

/**
 * Ingresos reales de la plataforma (el negocio SaaS en si, no los negocios
 * clientes del POS). Version simplificada del legacy: ese calculaba tambien
 * gastos reales (servidor, IA via OpenRouter, WhatsApp, comision de Wompi) y
 * un margen neto - aqui esta API no tiene esa infraestructura de costos (ni
 * el modulo de IA, fuera de alcance), asi que este servicio se limita a lo
 * que SI se puede calcular con datos reales: ingreso cobrado e ingreso
 * recurrente proyectado. Inventar costos de servidor/IA seria un numero
 * ficticio, peor que no mostrarlo.
 */
class PlatformFinanceService
{
    /**
     * @return array{year: int, month: int, is_current_month: bool, income: array{total_cop: int, count: int, by_payment_method: array<string, int>}, projection: ?array{days_elapsed: int, days_in_month: int, income_cop: int}}
     */
    public function monthlySummary(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();
        $now = Carbon::now();
        $isCurrentMonth = $now->year === $year && $now->month === $month;

        $payments = SaasSubscriptionPayment::whereBetween('paid_at', [$start->toDateString(), $end->toDateString()])->get();
        $incomeTotalCop = (int) $payments->sum('amount_cop');

        $projection = null;
        if ($isCurrentMonth) {
            $daysElapsed = max(1, $now->day);
            $daysInMonth = $start->daysInMonth;
            $factor = $daysInMonth / $daysElapsed;

            $projection = [
                'days_elapsed' => $daysElapsed,
                'days_in_month' => $daysInMonth,
                'income_cop' => (int) round($incomeTotalCop * $factor),
            ];
        }

        return [
            'year' => $year,
            'month' => $month,
            'is_current_month' => $isCurrentMonth,
            'income' => [
                'total_cop' => $incomeTotalCop,
                'count' => $payments->count(),
                'by_payment_method' => $payments->groupBy(fn (SaasSubscriptionPayment $p) => $p->payment_method ?? 'sin_especificar')
                    ->map(fn ($group) => (int) $group->sum('amount_cop'))
                    ->all(),
            ],
            'projection' => $projection,
        ];
    }

    /**
     * MRR real: cuanto deberia facturar el proximo ciclo si cada negocio con
     * suscripcion vigente renueva a su tarifa actual (respeta precio especial).
     */
    public function realMonthlyRecurringRevenueCop(): int
    {
        return (int) Business::where('paid_until', '>', now())
            ->get()
            ->sum(fn (Business $b) => $b->monthlyPriceCop());
    }

    /** Ingreso real ya cobrado en el mes en curso. Atajo para el Dashboard. */
    public function currentMonthIncomeCop(): int
    {
        $now = Carbon::now();

        return $this->monthlySummary($now->year, $now->month)['income']['total_cop'];
    }
}
