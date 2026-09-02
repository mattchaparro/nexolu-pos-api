<?php

namespace App\Capabilities\Expenses;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Capabilities\Support\ResolvesDateRange;
use App\Models\Business;
use App\Models\Expense;
use App\Models\User;

/** Tool: gastos_resumen. En que se fue la plata en un periodo. */
class ExpensesSummaryCapability implements Capability
{
    use CapsRows, ResolvesDateRange;

    public function requiredPermission(): ?string
    {
        return 'expenses.manage';
    }

    public function requiredFeature(): ?string
    {
        return 'expenses';
    }

    public function rules(): array
    {
        return [
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        [$start, $end] = $this->resolveDateRange($arguments['desde'] ?? null, $arguments['hasta'] ?? null);

        $expenses = Expense::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with('type:id,name')
            ->get(['id', 'type_id', 'value', 'scope']);

        $byType = [];
        foreach ($expenses as $expense) {
            $type = $expense->type?->name ?? 'Sin tipo';
            $byType[$type]['numero'] = ($byType[$type]['numero'] ?? 0) + 1;
            $byType[$type]['total'] = ($byType[$type]['total'] ?? 0.0) + (float) $expense->value;
        }

        $rows = [];
        foreach ($byType as $type => $data) {
            $rows[] = ['tipo' => $type, 'numero' => $data['numero'], 'total' => round($data['total'], 2)];
        }

        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'desde' => $start->toDateString(),
            'hasta' => $end->toDateString(),
            'total_gastos' => round((float) $expenses->sum('value'), 2),
            'por_tipo' => $this->capRows($rows),
        ];
    }
}
