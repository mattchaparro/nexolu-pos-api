<?php

namespace App\Services\SuperAdmin;

use App\Models\Business;
use App\Models\SaasSubscriptionPayment;
use App\Services\AiPlatformUsageService;
use App\Services\ExchangeRateService;
use App\Services\Messaging\Contracts\MessagingCostReporter;
use App\Services\SubscriptionPricingService;
use App\Support\SystemConfigStore;
use Illuminate\Support\Carbon;

/**
 * Ganancias y gastos reales de la plataforma (el negocio SaaS en si, no los
 * negocios clientes del POS). Todo se calcula en vivo, sin cache ni jobs: el
 * trafico de esta pantalla es solo el dueno revisando sus numeros.
 *
 * Gastos cubiertos: servidor, dominio (anual, prorrateado al mes), IA (costo
 * real vs el IA Core) y mensajeria (hoy WhatsApp, vs MessagingCostReporter -
 * ver ese contrato). Todo gasto en dolares se convierte con la TRM real del
 * dia (ver ExchangeRateService), no con una tasa fija - una constante
 * desactualizada distorsiona justo el numero que decide el margen.
 *
 * A diferencia de legacy, no incluye comision de Wompi: esta API ya no cobra
 * suscripciones via Wompi directo (ver Nexolu Payments Core), asi que esa
 * comision no es un gasto que esta plataforma pague.
 */
class PlatformFinanceService
{
    public function __construct(
        private SubscriptionPricingService $pricing,
        private ExchangeRateService $exchangeRates,
        private AiPlatformUsageService $aiUsage,
        private MessagingCostReporter $messagingUsage,
    ) {}

    /**
     * @return array{
     *     year: int, month: int, is_current_month: bool,
     *     income: array{total_cop: int, count: int, by_payment_method: array<string, int>},
     *     expenses: array{
     *         usd_to_cop_rate: float, server_cop: int, domain_cop: int,
     *         ai_cop: int, ai_cost_available: bool, messaging_cop: int, messaging_cost_available: bool, total_cop: int,
     *     },
     *     margin: array{cop: int, percent: ?float},
     *     projection: ?array{days_elapsed: int, days_in_month: int, income_cop: int},
     * }
     */
    public function monthlySummary(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();
        $now = Carbon::now();
        $isCurrentMonth = $now->year === $year && $now->month === $month;

        $payments = SaasSubscriptionPayment::whereBetween('paid_at', [$start->toDateString(), $end->toDateString()])->get();
        $incomeTotalCop = (int) $payments->sum('amount_cop');

        $expenses = $this->expensesCop($start, $end);

        $marginCop = $incomeTotalCop - $expenses['total_cop'];
        $marginPercent = $incomeTotalCop > 0 ? round(($marginCop / $incomeTotalCop) * 100, 1) : null;

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
            'expenses' => $expenses,
            'margin' => [
                'cop' => $marginCop,
                'percent' => $marginPercent,
            ],
            'projection' => $projection,
        ];
    }

    /**
     * @return array{usd_to_cop_rate: float, server_cop: int, domain_cop: int, ai_cop: int, ai_cost_available: bool, messaging_cop: int, messaging_cost_available: bool, total_cop: int}
     */
    private function expensesCop(Carbon $start, Carbon $end): array
    {
        // La TRM real del dia manda sobre la tasa fija de configuracion. Esa
        // constante se desactualiza con el tiempo; un 27% de error sobre TODO
        // gasto en dolares es justo lo que decide el margen.
        $usdToCopRate = $this->exchangeRates->rateForDate(Carbon::now())
            ?: (float) SystemConfigStore::get('finance.usd_to_cop_rate', '4000');

        $serverCostUsd = (float) SystemConfigStore::get('finance.server_cost_usd', '12');
        // Dominio: gasto fijo anual, prorrateado al mes.
        $domainCostUsdYear = (float) SystemConfigStore::get('finance.domain_cost_usd_year', '15');

        $aiCostUsd = $this->aiUsage->costUsdForPeriod($start->toDateString(), $end->toDateString());
        $messagingCostUsd = $this->messagingUsage->costUsdForPeriod($start->toDateString(), $end->toDateString());

        $serverCop = (int) round($serverCostUsd * $usdToCopRate);
        $domainCop = (int) round(($domainCostUsdYear / 12) * $usdToCopRate);
        $aiCop = $aiCostUsd !== null ? (int) round($aiCostUsd * $usdToCopRate) : 0;
        $messagingCop = $messagingCostUsd !== null ? (int) round($messagingCostUsd * $usdToCopRate) : 0;

        return [
            'usd_to_cop_rate' => $usdToCopRate,
            'server_cop' => $serverCop,
            'domain_cop' => $domainCop,
            'ai_cop' => $aiCop,
            // Si el IA Core no respondio, el costo de IA se excluye del total
            // en vez de mostrarse en $0 - un margen "mejor" por una falla de
            // red seria peor que avisar que ese dato no esta disponible.
            'ai_cost_available' => $aiCostUsd !== null,
            'messaging_cop' => $messagingCop,
            'messaging_cost_available' => $messagingCostUsd !== null,
            'total_cop' => $serverCop + $domainCop + $aiCop + $messagingCop,
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
            ->sum(fn (Business $b) => $this->pricing->totalCop($b));
    }

    /** Ingreso real ya cobrado en el mes en curso. Atajo para el Dashboard. */
    public function currentMonthIncomeCop(): int
    {
        $now = Carbon::now();

        return $this->monthlySummary($now->year, $now->month)['income']['total_cop'];
    }
}
