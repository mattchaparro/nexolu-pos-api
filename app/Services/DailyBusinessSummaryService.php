<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Sale;
use App\Support\LowStockAlertReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Como le fue al negocio hoy": el calculo deterministico detras del resumen
 * diario de WhatsApp (ver SendDailyWhatsAppSummary).
 *
 * Consolida lo que en el monolito legacy vivia repartido entre
 * ResumenInteligenteInsight y sus 6 sub-insights (Panorama, Gastos,
 * Ingredientes, Cierres, Fiados, CuentasPorPagar) en una sola clase: aqui no
 * hay tarjetas de dashboard ni cache de prosa generada por IA que justifiquen
 * esa separacion en clases con su propia interfaz (AiInsightDefinition) -- lo
 * unico que existe en esta API es la plantilla de WhatsApp, que consume
 * exactamente estos numeros. Los numeros SIEMPRE se calculan aqui en PHP; el
 * comando que arma la plantilla nunca inventa ni redondea distinto.
 *
 * Bug de legacy corregido al portar (no solo trasladado): calcularSalud()
 * contaba "productos por agotarse" a partir de la lista de nombres ya topada
 * a 3 para mostrar en el dashboard, asi que un negocio con 20 productos cerca
 * de agotarse computaba salud igual que uno con 3. Aqui se cuenta el total
 * real de productos bajo umbral, no la lista topada para mostrar.
 */
class DailyBusinessSummaryService
{
    private const REFERENCE_WEEKS = 4;

    /**
     * @return array{
     *     health_level: string,
     *     health_factor: string,
     *     sales_today: float,
     *     sales_today_vs_yesterday_pct: ?float,
     *     expenses_today: float,
     *     unusual_expense: bool,
     *     priority: ?array{type: string, name: string, text: string},
     * }
     */
    public function gatherData(Business $business): array
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();

        $salesToday = $this->salesBetween($business->id, $today, $now);
        $averageSameWeekday = $this->averageSameWeekdayFullDay($business->id, $today);

        $lowStockReport = LowStockAlertReport::forBusiness($business);
        $lowStockProducts = $this->urgencyFor($lowStockReport['items'], 'product');
        $lowStockIngredients = $business->hasFeature('ingredients') ? $this->urgencyFor($lowStockReport['items'], 'ingredient') : null;
        $cashClosing = $business->hasFeature('cash_closing') ? $this->cashClosingShortfalls($business->id) : null;
        $expensesThisMonth = $business->hasFeature('expenses') ? $this->expensesThisMonthVsLastMonth($business->id, $now) : null;
        $oldestReceivable = $business->hasFeature('receivables') ? $this->oldestReceivable($business->id, $now) : null;
        $oldestPayable = $business->hasFeature('inventory') ? $this->oldestPayable($business->id, $now) : null;

        $expensesToday = $this->expensesOn($business->id, $now->toDateString());
        $unusualExpense = $expensesThisMonth !== null && $this->isUnusualExpense($business->id, $now, $expensesToday);

        [$healthLevel, $healthFactor] = $this->calculateHealth(
            salesToday: $salesToday['total'],
            salesTodayCount: $salesToday['count'],
            averageSameWeekday: $averageSameWeekday,
            lowStockProductsCount: $lowStockProducts['count'],
            lowStockIngredientsCount: $lowStockIngredients['count'] ?? 0,
            cashClosing: $cashClosing,
            expensesThisMonth: $expensesThisMonth,
        );

        $priority = $this->choosePriority(
            mostUrgentIngredient: $lowStockIngredients['mostUrgent'] ?? null,
            mostUrgentProduct: $lowStockProducts['mostUrgent'],
            oldestReceivable: $oldestReceivable,
            oldestPayable: $oldestPayable,
        );

        return [
            'health_level' => $healthLevel,
            'health_factor' => $healthFactor,
            'sales_today' => round($salesToday['total'], 2),
            'sales_today_vs_yesterday_pct' => $this->salesTodayVsYesterdaySameCutoff($business->id, $now, $salesToday['total']),
            'expenses_today' => round($expensesToday, 2),
            'unusual_expense' => $unusualExpense,
            'priority' => $priority,
        ];
    }

    /**
     * Igual que ResumenInteligenteInsight::valeLaPena(): incluso "vas bien,
     * sin novedades" es un veredicto util, pero sin ni ventas de referencia ni
     * nada prioritario no hay lectura que dar.
     */
    public function isWorthSending(array $data): bool
    {
        return $data['sales_today'] > 0
            || $data['priority'] !== null
            || $data['health_level'] !== 'green';
    }

    /** @return array{total: float, count: int} */
    private function salesBetween(int $businessId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = Sale::query()
            ->where('business_id', $businessId)
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->whereBetween('closed_at', [$from, $to]);

        return [
            'total' => (float) (clone $query)->sum('total'),
            'count' => (int) (clone $query)->count(),
        ];
    }

    private function salesTodayVsYesterdaySameCutoff(int $businessId, CarbonImmutable $now, float $salesToday): ?float
    {
        $yesterday = $now->startOfDay()->subDay();
        $cutoffYesterday = $yesterday->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
        $salesYesterday = $this->salesBetween($businessId, $yesterday, $cutoffYesterday)['total'];

        if ($salesYesterday <= 0) {
            return null;
        }

        return round((($salesToday - $salesYesterday) / $salesYesterday) * 100, 1);
    }

    /** Promedio del mismo dia de la semana (dia completo) en las ultimas semanas de referencia. */
    private function averageSameWeekdayFullDay(int $businessId, CarbonImmutable $today): float
    {
        $totals = [];
        for ($i = 1; $i <= self::REFERENCE_WEEKS; $i++) {
            $day = $today->subWeeks($i);
            $totals[] = $this->salesBetween($businessId, $day, $day->endOfDay())['total'];
        }

        $withSales = array_values(array_filter($totals, fn ($t) => $t > 0));

        return $withSales !== [] ? array_sum($withSales) / count($withSales) : 0.0;
    }

    private function expensesOn(int $businessId, string $date): float
    {
        return (float) DB::table('expenses')
            ->where('business_id', $businessId)
            ->where('date', $date)
            ->sum('value');
    }

    /**
     * Anomalia simple: el gasto de hoy contra el promedio del mismo dia de la
     * semana en las ultimas 4 semanas. Solo se marca con referencia real y mas
     * del doble de lo normal.
     */
    private function isUnusualExpense(int $businessId, CarbonImmutable $now, float $expenseToday): bool
    {
        if ($expenseToday <= 0) {
            return false;
        }

        $totals = [];
        for ($i = 1; $i <= self::REFERENCE_WEEKS; $i++) {
            $totals[] = $this->expensesOn($businessId, $now->subWeeks($i)->toDateString());
        }

        $withData = array_values(array_filter($totals, fn ($t) => $t > 0));
        if ($withData === []) {
            return false;
        }

        $average = array_sum($withData) / count($withData);

        return $average > 0 && $expenseToday > $average * 2;
    }

    /** @return array{total_this_month: float, total_last_month: float} */
    private function expensesThisMonthVsLastMonth(int $businessId, CarbonImmutable $now): array
    {
        $startOfMonth = $now->startOfMonth();
        $startOfLastMonth = $startOfMonth->subMonth();
        $endOfLastMonth = $startOfMonth->subSecond();

        $totalThisMonth = (float) Expense::query()
            ->where('business_id', $businessId)
            ->whereBetween('date', [$startOfMonth->toDateString(), $now->toDateString()])
            ->sum('value');

        $totalLastMonth = (float) Expense::query()
            ->where('business_id', $businessId)
            ->whereBetween('date', [$startOfLastMonth->toDateString(), $endOfLastMonth->toDateString()])
            ->sum('value');

        return ['total_this_month' => $totalThisMonth, 'total_last_month' => $totalLastMonth];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items  ya ordenados por urgencia via LowStockAlertReport
     * @return array{count: int, mostUrgent: ?array{name: string, daysLeft: int}}
     */
    private function urgencyFor(Collection $items, string $kind): array
    {
        $matching = $items->filter(fn (array $item) => $item['kind'] === $kind)->values();

        // Solo se reporta "el mas urgente" cuando tiene stock propio del cual
        // depender y consumo reciente real que lo respalde: uno de receta
        // depende de sus ingredientes, no del suyo, y sin movimiento no es una
        // urgencia, es candidato a que el umbral este mal ajustado.
        $candidate = $matching->first(fn (array $item) => ! $item['is_recipe'] && $item['coverage_days'] !== null);

        return [
            'count' => $matching->count(),
            'mostUrgent' => $candidate ? ['name' => $candidate['name'], 'daysLeft' => (int) $candidate['coverage_days']] : null,
        ];
    }

    /** @return array{total: int, shorts: int} */
    private function cashClosingShortfalls(int $businessId): array
    {
        $closings = DB::table('cash_closings')
            ->where('business_id', $businessId)
            ->orderByDesc('date')
            ->limit(30)
            ->get(['difference']);

        return [
            'total' => $closings->count(),
            'shorts' => $closings->filter(fn ($c) => (float) ($c->difference ?? 0) <= -1)->count(),
        ];
    }

    /** @return ?array{customer: string, days: int} */
    private function oldestReceivable(int $businessId, CarbonImmutable $now): ?array
    {
        $oldest = DB::table('receivables')
            ->where('business_id', $businessId)
            ->where('status', '!=', 'paid')
            ->where('balance', '>', 0)
            ->orderBy('created_at')
            ->first(['customer_name', 'created_at']);

        if (! $oldest) {
            return null;
        }

        return [
            'customer' => $oldest->customer_name ?: 'Sin nombre',
            'days' => (int) CarbonImmutable::parse($oldest->created_at)->diffInDays($now),
        ];
    }

    /**
     * requiredFeature() = 'inventory' en vez de un feature dedicado de cuentas
     * por pagar: se mantiene igual que legacy (ver CuentasPorPagarInsight),
     * no es un bug listado en CONTEXT.md, solo una decision de gating existente.
     *
     * @return ?array{supplier: string, days: int}
     */
    private function oldestPayable(int $businessId, CarbonImmutable $now): ?array
    {
        // Saldo por compra en subconsultas aparte: un join directo a lineas Y a
        // abonos a la vez multiplica filas y corrompe la suma.
        $oldest = DB::table('purchases as p')
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->where('p.business_id', $businessId)
            ->where('p.payment_status', 'pending')
            ->selectRaw('COALESCE(s.name, "Sin proveedor") as supplier, p.purchased_at')
            ->selectRaw('(select coalesce(sum(pl.line_total_cop), 0) from purchase_lines pl where pl.purchase_id = p.id) as total')
            ->selectRaw('(select coalesce(sum(pp.amount), 0) from purchase_payments pp where pp.purchase_id = p.id) as paid')
            ->orderBy('p.purchased_at')
            ->get()
            ->first(fn ($row) => ((float) $row->total - (float) $row->paid) > 0.009);

        if (! $oldest) {
            return null;
        }

        return [
            'supplier' => $oldest->supplier,
            'days' => (int) CarbonImmutable::parse($oldest->purchased_at)->diffInDays($now),
        ];
    }

    /**
     * "Salud" es el eslabon mas debil, no un promedio: un negocio con ventas
     * excelentes pero varios productos a punto de agotarse no esta "bien en
     * general", esta en riesgo de dejar de venderlos manana.
     *
     * @param  ?array{total: int, shorts: int}  $cashClosing
     * @param  ?array{total_this_month: float, total_last_month: float}  $expensesThisMonth
     * @return array{0: string, 1: string}
     */
    private function calculateHealth(
        float $salesToday,
        int $salesTodayCount,
        float $averageSameWeekday,
        int $lowStockProductsCount,
        int $lowStockIngredientsCount,
        ?array $cashClosing,
        ?array $expensesThisMonth,
    ): array {
        $factors = [];

        if ($averageSameWeekday > 0 && $salesTodayCount > 0) {
            $ratio = $salesToday / $averageSameWeekday;
            $factors[] = $ratio >= 0.9
                ? ['level' => 2, 'text' => 'ventas al ritmo de un dia normal o mejor']
                : ($ratio >= 0.6
                    ? ['level' => 1, 'text' => 'ventas algo por debajo de un dia normal']
                    : ['level' => 0, 'text' => 'ventas muy por debajo de un dia normal']);
        }

        $lowStockCount = $lowStockProductsCount + $lowStockIngredientsCount;
        $lowStockText = $lowStockCount.' producto'.($lowStockCount === 1 ? '' : 's').' por agotarse';
        $factors[] = $lowStockCount === 0
            ? ['level' => 2, 'text' => 'inventario sin urgencias']
            : ($lowStockCount <= 2
                ? ['level' => 1, 'text' => $lowStockText]
                : ['level' => 0, 'text' => $lowStockText]);

        if ($cashClosing !== null && $cashClosing['total'] > 0) {
            $shortRatio = $cashClosing['shorts'] / $cashClosing['total'];
            $factors[] = $shortRatio === 0.0
                ? ['level' => 2, 'text' => 'la caja viene cuadrando']
                : ($shortRatio < 0.3
                    ? ['level' => 1, 'text' => 'algun cierre de caja quedo corto']
                    : ['level' => 0, 'text' => 'varios cierres de caja quedaron cortos']);
        }

        if ($expensesThisMonth !== null && $expensesThisMonth['total_last_month'] > 0) {
            $variation = ($expensesThisMonth['total_this_month'] - $expensesThisMonth['total_last_month']) / $expensesThisMonth['total_last_month'];
            if ($variation > 0.3) {
                $factors[] = ['level' => 1, 'text' => 'los gastos van mas altos que el mes pasado'];
            }
        }

        if ($factors === []) {
            return ['green', 'sin suficiente historial todavia'];
        }

        $worst = collect($factors)->sortBy('level')->first();
        $level = match ($worst['level']) {
            2 => 'green',
            1 => 'yellow',
            default => 'red',
        };

        return [$level, $worst['text']];
    }

    /**
     * Lo unico de mayor urgencia, en orden: insumo/producto a punto de
     * agotarse, fiado muy viejo, cuenta por pagar muy vieja. Null si nada
     * amerita destacarse por encima de lo normal.
     *
     * @param  ?array{name: string, daysLeft: int}  $mostUrgentIngredient
     * @param  ?array{name: string, daysLeft: int}  $mostUrgentProduct
     * @param  ?array{customer: string, days: int}  $oldestReceivable
     * @param  ?array{supplier: string, days: int}  $oldestPayable
     * @return ?array{type: string, name: string, text: string}
     */
    private function choosePriority(
        ?array $mostUrgentIngredient,
        ?array $mostUrgentProduct,
        ?array $oldestReceivable,
        ?array $oldestPayable,
    ): ?array {
        if ($mostUrgentIngredient !== null && $mostUrgentIngredient['daysLeft'] <= 3) {
            return [
                'type' => 'ingredient',
                'name' => $mostUrgentIngredient['name'],
                'text' => 'el insumo "'.$mostUrgentIngredient['name'].'" se agotaria en '.$mostUrgentIngredient['daysLeft'].' dia(s)',
            ];
        }

        if ($mostUrgentProduct !== null && $mostUrgentProduct['daysLeft'] <= 3) {
            return [
                'type' => 'product',
                'name' => $mostUrgentProduct['name'],
                'text' => 'el producto "'.$mostUrgentProduct['name'].'" se agotaria en '.$mostUrgentProduct['daysLeft'].' dia(s)',
            ];
        }

        if ($oldestReceivable !== null && $oldestReceivable['days'] >= 30) {
            return [
                'type' => 'receivable',
                'name' => $oldestReceivable['customer'],
                'text' => $oldestReceivable['customer'].' te debe hace '.$oldestReceivable['days'].' dias',
            ];
        }

        if ($oldestPayable !== null && $oldestPayable['days'] >= 30) {
            return [
                'type' => 'payable',
                'name' => $oldestPayable['supplier'],
                'text' => 'le debes a '.$oldestPayable['supplier'].' hace '.$oldestPayable['days'].' dias',
            ];
        }

        return null;
    }
}
