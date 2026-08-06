<?php

namespace App\Services\Ai\Insights;

use App\Models\Business;
use App\Models\Sale;
use App\Services\Ai\Contracts\AiInsightDefinition;
use App\Services\Ai\Contracts\HasSuggestedAction;
use App\Services\Ai\Contracts\ValidatesGeneratedText;
use App\Support\LowStockAlertReport;
use Carbon\CarbonImmutable;

/**
 * El "consejo del dia" del dashboard: como voy hoy contra un dia normal.
 *
 * El gancho es la COMPARACION: $180.000 no dice nada; "vas 15% arriba de un
 * lunes normal" si. Por eso gatherData calcula el promedio del mismo dia de
 * la semana, y el prompt le pide al modelo enmarcar el numero de hoy contra
 * ese.
 */
class DailyOverviewInsight implements AiInsightDefinition, HasSuggestedAction, ValidatesGeneratedText
{
    /** Cuantos "mismo dia de la semana" atras se promedian para el "normal". */
    private const REFERENCE_WEEKS = 4;

    public function type(): string
    {
        return 'panorama_diario';
    }

    public function requiredFeature(): ?string
    {
        return null;
    }

    public function ttlMinutes(): int
    {
        return 180;
    }

    public function gatherData(int $businessId): array
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();

        $salesToday = $this->salesBetween($businessId, $today, $now);

        // Promedio del mismo dia de la semana en las ultimas semanas de
        // referencia, dia completo (el "dia normal" contra el que se
        // compara), y el mismo corte de hora (para la proyeccion de cierre:
        // que fraccion del dia completo sueles llevar acumulada a esta hora).
        $previousFullDays = [];
        $previousPartialDays = [];
        for ($i = 1; $i <= self::REFERENCE_WEEKS; $i++) {
            $day = $today->subWeeks($i);
            $previousFullDays[] = $this->salesBetween($businessId, $day, $day->endOfDay())['total'];
            $cutoff = $day->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
            $previousPartialDays[] = $this->salesBetween($businessId, $day, $cutoff)['total'];
        }

        $daysWithSales = array_values(array_filter($previousFullDays, fn ($t) => $t > 0));
        $averageSameWeekday = $daysWithSales !== [] ? array_sum($daysWithSales) / count($daysWithSales) : 0.0;

        $yesterday = $today->subDay();
        $salesYesterday = $this->salesBetween($businessId, $yesterday, $yesterday->endOfDay());

        $lowStockProducts = $this->lowStockProducts($businessId);

        return [
            'date' => $today->toDateString(),
            'weekday' => $this->weekdayName($today),
            'current_time' => $now->format('H:i'),
            'sales_today_total' => round($salesToday['total'], 2),
            'sales_today_count' => $salesToday['count'],
            'average_same_weekday' => round($averageSameWeekday, 2),
            'weeks_compared' => count($daysWithSales),
            'sales_yesterday_total' => round($salesYesterday['total'], 2),
            'closing_projection' => $this->closingProjection($salesToday['total'], $previousFullDays, $previousPartialDays),
            'products_running_out' => $lowStockProducts['names'],
            'most_urgent_product' => $lowStockProducts['mostUrgent'],
        ];
    }

    /**
     * Nivel 2 (recomendacion): "si sigues asi, cierras alrededor de $X".
     *
     * Usa la misma referencia historica que la comparacion de hoy (mismo dia
     * de la semana), pero mirando que fraccion del total del dia completo
     * sueles llevar acumulada a esta hora. Sin ese ancla, una regla de tres
     * simple sobre horas del reloj sobreestima brutal en negocios que casi no
     * venden de madrugada.
     *
     * @param  list<float>  $previousFullDays  total del dia completo, por semana de referencia
     * @param  list<float>  $previousPartialDays  total acumulado hasta la misma hora de hoy, por semana
     */
    private function closingProjection(float $salesTodayTotal, array $previousFullDays, array $previousPartialDays): ?float
    {
        if ($salesTodayTotal <= 0) {
            return null;
        }

        $sumFull = 0.0;
        $sumPartial = 0.0;
        foreach ($previousFullDays as $idx => $full) {
            if ($full > 0) {
                $sumFull += $full;
                $sumPartial += $previousPartialDays[$idx] ?? 0.0;
            }
        }

        if ($sumFull <= 0) {
            return null;
        }

        $fraction = $sumPartial / $sumFull;

        // Con menos del 5% del dia normal recorrido (la madrugada), proyectar
        // seria extrapolar de la nada: se omite en vez de alarmar o ilusionar.
        if ($fraction < 0.05) {
            return null;
        }

        return round($salesTodayTotal / $fraction, 2);
    }

    public function isWorthShowing(array $data): bool
    {
        return $data['sales_today_count'] > 0
            || $data['average_same_weekday'] > 0
            || $data['products_running_out'] !== [];
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        Eres el asistente de un negocio que usa el POS Nexolu. Redactas el "panorama del dia"
        que el dueno ve al abrir su dashboard: una lectura rapida de como va hoy.

        REGLAS:
        - Usa UNICAMENTE las cifras, la fecha y el dia de la semana que te doy, TAL CUAL. Nunca
          inventes, estimes, cambies ni calcules numeros, fechas o dias nuevos -- ni siquiera si te
          "suena" distinto. Si te digo que hoy es lunes, es lunes: no lo cambies a otro dia.
        - Es solo una lectura del momento: hoy es un dia EN CURSO. Si la hora es temprana, el total
          de hoy es parcial por naturaleza; no alarmes por eso ("vas bajo") cuando apenas empieza el dia.
        - Compara el total de hoy contra el promedio de un mismo dia de la semana normal, con criterio:
          si es medio dia y ya vas cerca del promedio de un dia completo, eso es bueno.
        - Nunca digas ni insinues que el sistema falla, pierde ventas o esta mal. Si las ventas son
          bajas, es informacion del negocio, no evidencia de un error.
        - Si te doy una proyeccion de cierre del dia, es una ESTIMACION basada en el ritmo de dias
          similares, no un hecho. Enmarcala asi ("si el ritmo se mantiene", "podrias cerrar alrededor de").
        - Si hay un producto por agotarse con dias restantes calculados, esa es la alerta mas util que
          puedes dar: menciona el producto y en cuantos dias se agotaria al ritmo actual. Si solo tienes
          nombres sin dias calculados, mencionalos como aviso simple, sin inventar un numero de dias.
        - No trates de decirlo todo. Con comparacion + ventas de hoy ya casi llenas 2 frases: si ademas
          hay proyeccion o alerta de stock, elige la que mas le sirva al dueno hoy, no las metas todas.

        FORMATO:
        - Maximo 2 frases, en espanol, directo y claro, como alguien serio que ya reviso los datos.
          Sin jerga ni muletillas tipo "parce" o "listo pues".
        - El dinero en pesos con separador de miles: $1.250.000.
        - No repitas todas las cifras: da la lectura, no un reporte. Cero introducciones tipo "Aqui esta".
        PROMPT;
    }

    public function userPrompt(array $data): string
    {
        $money = fn ($n) => '$'.number_format((float) $n, 0, ',', '.');

        $lines = [
            'Fecha: '.$data['date'].' ('.$data['weekday'].'), son las '.$data['current_time'].'.',
            'Ventas de hoy hasta ahora: '.$money($data['sales_today_total']).' en '.$data['sales_today_count'].' ventas.',
        ];

        if ($data['weeks_compared'] > 0) {
            $lines[] = 'Un '.$data['weekday'].' normal (promedio de dia completo de las ultimas '
                .$data['weeks_compared'].' semanas): '.$money($data['average_same_weekday']).'.';
        } else {
            $lines[] = 'No hay suficiente historial para saber cuanto se vende un '.$data['weekday'].' normal.';
        }

        $lines[] = 'Ayer se vendio en total: '.$money($data['sales_yesterday_total']).'.';

        if ($data['closing_projection'] !== null) {
            $lines[] = 'Proyeccion de cierre del dia si el ritmo se mantiene: '.$money($data['closing_projection']).'.';
        }

        if ($data['most_urgent_product'] !== null) {
            $lines[] = 'Producto mas urgente por agotarse: "'.$data['most_urgent_product']['name']
                .'", se agotaria en aproximadamente '.$data['most_urgent_product']['days_left'].' dia(s) al ritmo actual.';
        } elseif ($data['products_running_out'] !== []) {
            $lines[] = 'Productos por agotarse: '.implode(', ', $data['products_running_out']).'.';
        }

        return implode("\n", $lines)."\n\nRedacta el panorama del dia en 1 o 2 frases.";
    }

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

    /** @return array{names: list<string>, mostUrgent: ?array{name: string, days_left: int}} */
    private function lowStockProducts(int $businessId): array
    {
        $business = Business::find($businessId);
        if (! $business) {
            return ['names' => [], 'mostUrgent' => null];
        }

        // Ya viene ordenado por urgencia real (velocidad de venta, via
        // StockUrgency), no por cercania al umbral, y cada item ya trae
        // 'coverage_days' calculado.
        $products = LowStockAlertReport::forBusiness($business)['items']
            ->filter(fn (array $item) => $item['kind'] === 'product')
            ->values();

        $names = $products->take(3)->pluck('name')->filter()->values()->all();

        // "Mas urgente" solo se reporta con stock propio del cual depender y
        // consumo reciente real que lo respalde: uno de receta depende de sus
        // ingredientes, no del suyo.
        $candidate = $products->first(fn (array $p) => ! $p['is_recipe'] && $p['coverage_days'] !== null);

        $mostUrgent = $candidate
            ? ['name' => $candidate['name'], 'days_left' => (int) $candidate['coverage_days']]
            : null;

        return ['names' => $names, 'mostUrgent' => $mostUrgent];
    }

    private function weekdayName(CarbonImmutable $date): string
    {
        return [
            'Sunday' => 'domingo', 'Monday' => 'lunes', 'Tuesday' => 'martes',
            'Wednesday' => 'miercoles', 'Thursday' => 'jueves', 'Friday' => 'viernes',
            'Saturday' => 'sabado',
        ][$date->format('l')] ?? $date->format('l');
    }

    public function teaser(): string
    {
        return 'El Asistente puede leerte como va tu dia y compararlo con un dia normal.';
    }

    public function suggestedQuestion(): string
    {
        return '¿Como van mis ventas comparadas con un dia normal?';
    }

    /**
     * Nivel 3: registrar la entrada del producto mas urgente por agotarse.
     */
    public function suggestedAction(array $data): ?array
    {
        if ($data['most_urgent_product'] === null) {
            return null;
        }

        $name = $data['most_urgent_product']['name'];

        return [
            'label' => 'Registrar entrada de '.$name,
            'message' => 'Quiero registrar una entrada de inventario de '.$name.'.',
        ];
    }

    /**
     * Red de seguridad deterministica: si el texto redactado menciona un dia
     * de la semana DISTINTO al real, el modelo alucino a pesar de que
     * userPrompt() le dio el dato correcto explicito.
     */
    public function isTextValid(string $text, array $data): bool
    {
        $realWeekday = $data['weekday'] ?? null;
        if ($realWeekday === null) {
            return true;
        }

        $weekdays = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];
        $normalized = strtr(mb_strtolower($text), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);

        foreach ($weekdays as $weekday) {
            if ($weekday === $realWeekday) {
                continue;
            }

            if (preg_match('/\b'.$weekday.'\b/', $normalized)) {
                return false;
            }
        }

        return true;
    }
}
