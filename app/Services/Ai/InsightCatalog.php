<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiInsightDefinition;
use App\Services\Ai\Insights\CashClosingHistoryInsight;
use App\Services\Ai\Insights\DailyOverviewInsight;
use App\Services\Ai\Insights\ExpensesSummaryInsight;
use App\Services\Ai\Insights\IngredientsSummaryInsight;
use App\Services\Ai\Insights\PayablesSummaryInsight;
use App\Services\Ai\Insights\ReceivablesSummaryInsight;
use App\Services\Ai\Insights\SmartSummaryInsight;

/**
 * Registro estatico de todos los insights disponibles, en el orden en que se
 * mostrarian en el dashboard - el resumen inteligente abre, el resto son las
 * tarjetas por pantalla.
 */
class InsightCatalog
{
    /** @return array<string, AiInsightDefinition> keyed por tipo() */
    public static function all(): array
    {
        $definitions = [
            new SmartSummaryInsight,
            new DailyOverviewInsight,
            new ExpensesSummaryInsight,
            new IngredientsSummaryInsight,
            new CashClosingHistoryInsight,
            new ReceivablesSummaryInsight,
            new PayablesSummaryInsight,
        ];

        $byType = [];
        foreach ($definitions as $definition) {
            $byType[$definition->type()] = $definition;
        }

        return $byType;
    }

    public static function find(string $type): ?AiInsightDefinition
    {
        return self::all()[$type] ?? null;
    }
}
