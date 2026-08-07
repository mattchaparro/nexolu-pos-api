<?php

namespace App\Services\Ai\Insights;

use App\Models\Business;
use App\Models\Sale;
use App\Services\Ai\Contracts\AiInsightDefinition;
use App\Services\Ai\Contracts\HasSuggestedAction;
use App\Support\LowStockAlertReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Resumen inteligente del dia": la tarjeta de apertura del dashboard.
 *
 * En 20-30 segundos: como va el negocio, que cambio, y que es lo mas urgente.
 * NO recalcula nada que ya viva en otro insight: compone gatherData() de
 * DailyOverviewInsight, ExpensesSummaryInsight, IngredientsSummaryInsight,
 * CashClosingHistoryInsight, ReceivablesSummaryInsight y PayablesSummaryInsight.
 * Dos motivos: no contradecir esas tarjetas con un numero calculado distinto,
 * y no pagar el costo de mantener la misma cuenta en dos sitios.
 *
 * Tambien es el que consume `notifications:send-daily-whatsapp-summary`
 * directamente via gatherData() (sin pasar por AiInsightService ni el
 * modelo): esa notificacion no le cuesta tokens de IA a proposito, son los
 * mismos numeros ya calculados en PHP, solo van por otro canal en vez de
 * redactarse.
 *
 * "Salud del negocio" y "que es mas urgente" se calculan aqui, en PHP,
 * deterministas - el modelo de IA solo los redacta.
 */
class SmartSummaryInsight implements AiInsightDefinition, HasSuggestedAction
{
    private const REFERENCE_WEEKS = 4;

    public function type(): string
    {
        return 'resumen_inteligente';
    }

    public function requiredFeature(): ?string
    {
        return null; // Todo negocio vende, asi que aplica a todos.
    }

    public function ttlMinutes(): int
    {
        return 60;
    }

    public function gatherData(int $businessId): array
    {
        $business = Business::find($businessId);
        $now = CarbonImmutable::now();

        $overview = (new DailyOverviewInsight)->gatherData($businessId);
        $expenses = $this->ifHasFeature($business, 'expenses', fn () => (new ExpensesSummaryInsight)->gatherData($businessId));
        $ingredients = $this->ifHasFeature($business, 'ingredients', fn () => (new IngredientsSummaryInsight)->gatherData($businessId));
        $cashClosing = $this->ifHasFeature($business, 'cash_closing', fn () => (new CashClosingHistoryInsight)->gatherData($businessId));
        $receivables = $this->ifHasFeature($business, 'receivables', fn () => (new ReceivablesSummaryInsight)->gatherData($businessId));
        $payables = $this->ifHasFeature($business, 'inventory', fn () => (new PayablesSummaryInsight)->gatherData($businessId));

        $salesTodayVsYesterdayPct = $this->salesTodayVsYesterdaySameCutoff($businessId, $now, $overview['sales_today_total']);
        $expensesToday = $this->expensesOn($businessId, $now->toDateString());
        $unusualExpense = $expenses !== null && $this->isUnusualExpense($businessId, $now, $expensesToday);

        // Total real de productos bajo umbral, no la lista topada a 3 nombres
        // que trae DailyOverviewInsight para mostrar (ver docblock de
        // calculateHealth: contar sobre esa lista subestima la salud de
        // negocios con mas de 3 productos por agotarse).
        $lowStockProductsCount = $business
            ? LowStockAlertReport::forBusiness($business)['items']->where('kind', 'product')->count()
            : 0;

        [$healthLevel, $healthFactor] = $this->calculateHealth(
            salesToday: $overview['sales_today_total'],
            salesTodayCount: $overview['sales_today_count'],
            averageSameWeekday: $overview['average_same_weekday'],
            lowStockProductsCount: $lowStockProductsCount,
            lowStockIngredientsCount: $ingredients['below_minimum_count'] ?? 0,
            cashClosing: $cashClosing,
            expenses: $expenses,
        );

        $priority = $this->choosePriority($ingredients, $overview, $receivables, $payables);

        return [
            'health_level' => $healthLevel,
            'health_factor' => $healthFactor,
            'sales_today_total' => $overview['sales_today_total'],
            'sales_today_vs_yesterday_pct' => $salesTodayVsYesterdayPct,
            'expenses_today' => round($expensesToday, 2),
            'unusual_expense' => $unusualExpense,
            'priority' => $priority,
        ];
    }

    /** @param  ?callable(): array  $callback */
    private function ifHasFeature(?Business $business, string $feature, callable $callback): ?array
    {
        return ($business && $business->hasFeature($feature)) ? $callback() : null;
    }

    private function salesTodayVsYesterdaySameCutoff(int $businessId, CarbonImmutable $now, float $salesToday): ?float
    {
        $yesterday = $now->startOfDay()->subDay();
        $cutoffYesterday = $yesterday->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
        $salesYesterday = $this->salesBetween($businessId, $yesterday, $cutoffYesterday);

        if ($salesYesterday <= 0) {
            return null;
        }

        return round((($salesToday - $salesYesterday) / $salesYesterday) * 100, 1);
    }

    private function salesBetween(int $businessId, CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) Sale::query()
            ->where('business_id', $businessId)
            ->where('status', 'closed')
            ->where('is_non_revenue', false)
            ->where('is_credit', false)
            ->whereBetween('closed_at', [$from, $to])
            ->sum('total');
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
     * semana en las ultimas 4 semanas (mismo criterio que la comparacion de
     * ventas de DailyOverviewInsight). Solo se marca con referencia real y
     * mas del doble de lo normal.
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

    /**
     * "Salud" es el eslabon mas debil, no un promedio: un negocio con ventas
     * excelentes pero varios productos a punto de agotarse no esta "bien en
     * general", esta en riesgo de dejar de venderlos manana.
     *
     * Bug real corregido al portar: el legacy contaba "productos por
     * agotarse" a partir de la lista de nombres ya topada a 3 para mostrar en
     * el dashboard, asi que un negocio con 20 productos cerca de agotarse
     * computaba la misma salud que uno con 3. Aqui se recibe el total real
     * (ver gatherData()).
     *
     * @param  ?array{total: int, balanced: int, short: int, total_shortfall: float}  $cashClosing
     * @param  ?array{month: string, total_this_month: float, total_last_month: float, top_categories: array}  $expenses
     * @return array{0: string, 1: string}
     */
    private function calculateHealth(
        float $salesToday,
        int $salesTodayCount,
        float $averageSameWeekday,
        int $lowStockProductsCount,
        int $lowStockIngredientsCount,
        ?array $cashClosing,
        ?array $expenses,
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
            $shortRatio = $cashClosing['short'] / $cashClosing['total'];
            $factors[] = $shortRatio === 0.0
                ? ['level' => 2, 'text' => 'la caja viene cuadrando']
                : ($shortRatio < 0.3
                    ? ['level' => 1, 'text' => 'algun cierre de caja quedo corto']
                    : ['level' => 0, 'text' => 'varios cierres de caja quedaron cortos']);
        }

        if ($expenses !== null && $expenses['total_last_month'] > 0) {
            $variation = ($expenses['total_this_month'] - $expenses['total_last_month']) / $expenses['total_last_month'];
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
     * @return array{type: string, name: string, text: string}|null
     */
    private function choosePriority(?array $ingredients, array $overview, ?array $receivables, ?array $payables): ?array
    {
        if ($ingredients !== null && $ingredients['most_urgent_ingredient'] !== null
            && $ingredients['most_urgent_ingredient']['days_left'] <= 3) {
            $u = $ingredients['most_urgent_ingredient'];

            return [
                'type' => 'ingredient',
                'name' => $u['name'],
                'text' => 'el insumo "'.$u['name'].'" se agotaria en '.$u['days_left'].' dia(s)',
            ];
        }

        if ($overview['most_urgent_product'] !== null && $overview['most_urgent_product']['days_left'] <= 3) {
            $u = $overview['most_urgent_product'];

            return [
                'type' => 'product',
                'name' => $u['name'],
                'text' => 'el producto "'.$u['name'].'" se agotaria en '.$u['days_left'].' dia(s)',
            ];
        }

        if ($receivables !== null && $receivables['oldest'] !== null && $receivables['oldest']['days'] >= 30) {
            $r = $receivables['oldest'];

            return [
                'type' => 'receivable',
                'name' => $r['customer'],
                'text' => $r['customer'].' te debe hace '.$r['days'].' dias',
            ];
        }

        if ($payables !== null && $payables['oldest'] !== null && $payables['oldest']['days'] >= 30) {
            $p = $payables['oldest'];

            return [
                'type' => 'payable',
                'name' => $p['supplier'],
                'text' => 'le debes a '.$p['supplier'].' hace '.$p['days'].' dias',
            ];
        }

        return null;
    }

    public function isWorthShowing(array $data): bool
    {
        // Es la tarjeta de apertura: incluso "vas bien, sin novedades" es un
        // veredicto util. Solo se omite si no hay ni ventas de referencia ni
        // nada prioritario que decir.
        return $data['sales_today_total'] > 0
            || $data['priority'] !== null
            || $data['health_level'] !== 'green';
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        Eres el asistente de un negocio que usa el POS Nexolu. Redactas el "resumen inteligente del
        dia": lo primero que el dueno lee al entrar, para entender en segundos como va todo.

        REGLAS:
        - Usa UNICAMENTE los datos que te doy. Nunca inventes ni calcules numeros nuevos.
        - NUNCA digas el nivel de salud como una etiqueta ("vas en amarillo", "estas en verde",
          "semaforo rojo"). Un color solo no le dice nada al dueno sobre QUE pasa. En su lugar, abre
          con la COMPARACION concreta que motiva ese nivel (el dato de referencia es el promedio de
          este mismo dia de la semana en las ultimas semanas, no un dia especifico). Ejemplos de la
          forma correcta:
            * salud amarilla por ventas bajas -> "Hoy vas un poco por debajo de lo que es normal para
              un [dia de la semana]."
            * salud verde -> "Hoy vas al ritmo de un dia normal" o "Hoy vas mejor que un dia normal."
            * salud roja por varios productos por agotarse -> "Tienes varios productos a punto de
              agotarse, eso es lo que mas pesa hoy."
          La idea: el dueno debe entender EN QUE se basa el veredicto sin tener que preguntar.
        - Si hay comparacion de ventas contra ayer, mencionala con el numero (no solo "vas mejor").
        - Si hay un gasto inusual hoy, avisalo como dato curioso, no como alarma: "hoy gastaste mas
          de lo normal para ser [dia]" si aplica, sin decir que es un error.
        - Si hay una prioridad, cierrala mencionandola como lo mas urgente de hoy.
        - Nunca digas que el sistema falla. Rojo o amarillo es informacion del negocio, no un error.

        FORMATO:
        - Maximo 3 frases (este resumen tiene mas que contar que los demas), en espanol, directo y
          claro, como alguien serio que ya reviso los datos por ti. Sin jerga ni muletillas tipo
          "parce", "listo pues" o similares: eso resta claridad, no suma cercania.
        - El dinero en pesos con separador de miles: $1.250.000.
        - Sin introducciones tipo "Aqui esta tu resumen".
        PROMPT;
    }

    public function userPrompt(array $data): string
    {
        $money = fn ($n) => '$'.number_format((float) $n, 0, ',', '.');

        $lines = [
            'Salud del negocio: '.$data['health_level'].' ('.$data['health_factor'].').',
            'Ventas de hoy hasta ahora: '.$money($data['sales_today_total']).'.',
        ];

        if ($data['sales_today_vs_yesterday_pct'] !== null) {
            $sign = $data['sales_today_vs_yesterday_pct'] >= 0 ? '+' : '';
            $lines[] = 'Comparado con ayer a la misma hora: '.$sign.$data['sales_today_vs_yesterday_pct'].'%.';
        }

        if ($data['unusual_expense']) {
            $lines[] = 'Hoy se ha gastado '.$money($data['expenses_today']).', mas del doble de lo normal para este dia de la semana.';
        }

        if ($data['priority'] !== null) {
            $lines[] = 'Lo mas urgente hoy: '.$data['priority']['text'].'.';
        } else {
            $lines[] = 'No hay nada urgente pendiente hoy.';
        }

        return implode("\n", $lines)."\n\nRedacta el resumen inteligente del dia en maximo 3 frases.";
    }

    public function teaser(): string
    {
        return 'El Asistente puede darte el resumen inteligente de tu dia en segundos: como vas, que cambio y que es lo mas urgente.';
    }

    public function suggestedQuestion(): string
    {
        return '¿Como va mi negocio hoy y que deberia priorizar?';
    }

    /**
     * Reusa la misma accion que ofrecerian IngredientsSummaryInsight o
     * DailyOverviewInsight para ese mismo item: la prioridad detectada aqui
     * ya viene de esos dos, asi que la accion es identica a la que verian en
     * esas tarjetas.
     */
    public function suggestedAction(array $data): ?array
    {
        $priority = $data['priority'] ?? null;

        if ($priority === null || ! in_array($priority['type'], ['ingredient', 'product'], true)) {
            return null;
        }

        return [
            'label' => 'Registrar entrada de '.$priority['name'],
            'message' => $priority['type'] === 'ingredient'
                ? 'Quiero registrar una entrada de '.$priority['name'].'.'
                : 'Quiero registrar una entrada de inventario de '.$priority['name'].'.',
        ];
    }
}
