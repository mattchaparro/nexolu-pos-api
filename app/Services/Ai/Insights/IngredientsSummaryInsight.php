<?php

namespace App\Services\Ai\Insights;

use App\Services\Ai\Contracts\AiInsightDefinition;
use App\Services\Ai\Contracts\HasSuggestedAction;
use App\Support\StockUrgency;
use Illuminate\Support\Facades\DB;

/**
 * La lectura con IA de la pestana de ingredientes del catalogo.
 *
 * Responde de un vistazo que insumos estan por acabarse. "Urgente" se mide
 * por velocidad real de consumo (StockUrgency), no por cuanto le falta al
 * minimo: un ingrediente con 1 unidad que casi no se usa no es mas urgente
 * que uno con 5 que se consume rapido.
 */
class IngredientsSummaryInsight implements AiInsightDefinition, HasSuggestedAction
{
    public function type(): string
    {
        return 'ingredientes_resumen';
    }

    public function requiredFeature(): ?string
    {
        return 'ingredients';
    }

    public function ttlMinutes(): int
    {
        return 240;
    }

    public function gatherData(int $businessId): array
    {
        $rows = DB::table('ingredients')
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->get(['id', 'name', 'unit', 'stock', 'min_stock', 'cost_price']);

        $belowMinimumRows = $rows
            ->filter(fn ($r) => (float) $r->min_stock > 0 && (float) $r->stock <= (float) $r->min_stock)
            ->values();

        $velocities = StockUrgency::ingredientsVelocityBatch($businessId, $belowMinimumRows->pluck('id')->all());

        $urgent = StockUrgency::sortByUrgency(
            $belowMinimumRows->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'stock' => (float) $r->stock,
                'coverage_days' => StockUrgency::coverageDays((float) $r->stock, $velocities[$r->id] ?? 0.0),
            ])
        );

        $examples = $urgent->take(4)->pluck('name')->values()->all();

        // Solo se reporta "el mas urgente" cuando de verdad hay consumo
        // reciente que lo respalde: un insumo bajo el minimo pero sin
        // movimiento no es una urgencia, es candidato a que el minimo este
        // mal ajustado (ver 'no_movement_count').
        $withMovement = $urgent->first(fn ($u) => $u['coverage_days'] !== null);
        $mostUrgent = $withMovement
            ? ['name' => $withMovement['name'], 'days_left' => (int) $withMovement['coverage_days']]
            : null;

        $noMovementCount = $urgent->filter(fn ($u) => $u['coverage_days'] === null)->count();

        $inventoryValue = $rows->sum(fn ($r) => (float) $r->stock * (float) $r->cost_price);

        return [
            'total_ingredients' => $rows->count(),
            'below_minimum_count' => $urgent->count(),
            'below_minimum_examples' => $examples,
            'most_urgent_ingredient' => $mostUrgent,
            'no_movement_count' => $noMovementCount,
            'inventory_value' => round($inventoryValue, 2),
        ];
    }

    public function isWorthShowing(array $data): bool
    {
        return $data['total_ingredients'] > 0;
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        Eres el asistente de un negocio (tipico un restaurante o cafeteria) que usa el POS Nexolu.
        Redactas una lectura breve del inventario de ingredientes/insumos.

        REGLAS:
        - Usa UNICAMENTE los datos que te doy. Nunca inventes numeros ni nombres.
        - Lo mas importante son los insumos por acabarse: avisalos, porque quedarse sin uno es dejar
          de poder vender un plato. Si no hay ninguno por debajo del minimo, dilo tranquilo, no inventes
          un problema.
        - Si te doy dias restantes calculados para el insumo mas urgente, es el dato mas util que puedes
          dar (ej. "el queso se te acaba en 2 dias"): usalo en vez de solo nombrar el insumo. Es una
          ESTIMACION del ritmo de consumo reciente, enmarcala como tal, no como un hecho exacto.
        - Si te digo que hay insumos bajo el minimo SIN consumo reciente, no los presentes como
          urgentes: sugiere de pasada revisar si el minimo configurado es el correcto para esos, en
          vez de sonar alarmante por algo que puede que ni se venda.
        - Menciona el valor del inventario solo si aporta.
        - Nunca digas que el sistema falla.

        FORMATO:
        - Maximo 2 frases, en espanol, directo y claro. Sin jerga ni muletillas tipo "parce" o "listo pues": habla como alguien serio que ya reviso los datos, no como un amigo casual.
        - El dinero en pesos con separador de miles: $1.250.000.
        - Da la lectura, no un listado largo. Sin introducciones tipo "Aqui esta".
        PROMPT;
    }

    public function userPrompt(array $data): string
    {
        $money = fn ($n) => '$'.number_format((float) $n, 0, ',', '.');

        $lines = ['El negocio tiene '.$data['total_ingredients'].' ingredientes activos.'];

        if ($data['below_minimum_count'] > 0) {
            $examples = array_filter($data['below_minimum_examples']);
            $lines[] = 'Por debajo del minimo (por acabarse): '.$data['below_minimum_count']
                .($examples !== [] ? ', entre ellos '.implode(', ', $examples).'.' : '.');

            if ($data['most_urgent_ingredient'] !== null) {
                $lines[] = 'El mas urgente, "'.$data['most_urgent_ingredient']['name']
                    .'", se agotaria en aproximadamente '.$data['most_urgent_ingredient']['days_left']
                    .' dia(s) al ritmo de consumo actual.';
            }

            if ($data['no_movement_count'] > 0) {
                $lines[] = $data['no_movement_count'].' de esos estan bajo el minimo pero sin consumo '
                    .'reciente (candidatos a que el minimo configurado este alto para lo que realmente rota).';
            }
        } else {
            $lines[] = 'Ninguno esta por debajo del minimo.';
        }

        $lines[] = 'Valor del inventario de insumos: '.$money($data['inventory_value']).'.';

        return implode("\n", $lines)."\n\nRedacta la lectura de ingredientes en 1 o 2 frases.";
    }

    public function teaser(): string
    {
        return 'El Asistente puede avisarte que insumos estan por acabarse.';
    }

    public function suggestedQuestion(): string
    {
        return '¿Que ingredientes estan por acabarse?';
    }

    public function suggestedAction(array $data): ?array
    {
        if ($data['most_urgent_ingredient'] === null) {
            return null;
        }

        $name = $data['most_urgent_ingredient']['name'];

        return [
            'label' => 'Registrar entrada de '.$name,
            'message' => 'Quiero registrar una entrada de '.$name.'.',
        ];
    }
}
