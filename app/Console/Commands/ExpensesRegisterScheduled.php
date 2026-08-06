<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\FixedExpenseTemplate;
use App\Services\ExpenseService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Registra automaticamente un Expense por cada plantilla de gasto fijo
 * activa cuyo day_of_month coincida con la fecha de corrida. Idempotente por
 * mes: si ya existe un Expense de esa plantilla en el mes en curso, no
 * duplica aunque el comando corra varias veces el mismo dia.
 */
#[Signature('expenses:register-scheduled {--date= : Fecha Y-m-d (default: hoy)}')]
#[Description('Registra automaticamente los gastos fijos programados para hoy')]
class ExpensesRegisterScheduled extends Command
{
    public function handle(ExpenseService $expenseService): int
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
            $alreadyExists = Expense::withoutGlobalScopes()
                ->where('fixed_expense_template_id', $template->id)
                ->whereYear('date', $today->year)
                ->whereMonth('date', $today->month)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            $expenseService->create([
                'business_id' => $template->business_id,
                'date' => $today->toDateString(),
                'description' => $template->name,
                'value' => $template->amount,
                'scope' => $template->scope,
                'type_id' => $template->expense_type_id,
                'fixed_expense_template_id' => $template->id,
            ]);

            $registered++;
        }

        $this->info("Gastos registrados: {$registered} (dia {$today->day} de {$today->format('Y-m')})");

        return self::SUCCESS;
    }
}
