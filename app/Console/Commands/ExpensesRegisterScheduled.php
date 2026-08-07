<?php

namespace App\Console\Commands;

use App\Models\FixedExpenseTemplate;
use App\Services\FixedExpenseTemplateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Registra automaticamente un Expense por cada plantilla de gasto fijo
 * activa cuyo day_of_month coincida con la fecha de corrida. Idempotente por
 * mes (via FixedExpenseTemplateService::registerForMonth() - misma logica
 * que usa el disparo manual desde la API): si ya existe un Expense de esa
 * plantilla en el mes en curso, no duplica aunque el comando corra varias
 * veces el mismo dia.
 */
#[Signature('expenses:register-scheduled {--date= : Fecha Y-m-d (default: hoy)}')]
#[Description('Registra automaticamente los gastos fijos programados para hoy')]
class ExpensesRegisterScheduled extends Command
{
    public function handle(FixedExpenseTemplateService $fixedExpenseTemplateService): int
    {
        $today = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();

        $templates = FixedExpenseTemplate::query()
            ->where('active', true)
            ->whereNotNull('day_of_month')
            ->where('day_of_month', $today->day)
            ->whereNotNull('amount')
            ->where('amount', '>', 0)
            ->get();

        $registered = 0;

        foreach ($templates as $template) {
            if ($fixedExpenseTemplateService->registerForMonth($template, $today->year, $today->month)) {
                $registered++;
            }
        }

        $this->info("Gastos registrados: {$registered} (dia {$today->day} de {$today->format('Y-m')})");

        return self::SUCCESS;
    }
}
