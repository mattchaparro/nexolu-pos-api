<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\FixedExpenseTemplate;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Registro puntual (manual o programado) del gasto de una plantilla, y el
 * recordatorio mensual asociado a ella. La creacion/edicion simple de la
 * plantilla en si vive directo en FixedExpenseTemplateController (sin logica
 * de negocio propia, igual que ExpenseTypeController) - aca solo lo que SI
 * tiene reglas: idempotencia por mes y el toggle de recordatorio.
 */
class FixedExpenseTemplateService
{
    public function __construct(
        private ExpenseService $expenseService,
        private ReminderService $reminderService,
    ) {}

    public function isRegisteredForMonth(FixedExpenseTemplate $template, int $year, int $month): bool
    {
        return Expense::withoutGlobalScopes()
            ->where('fixed_expense_template_id', $template->id)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->exists();
    }

    /**
     * Registra el gasto del mes desde la plantilla, si no existe ya uno. Usado
     * tanto por expenses:register-scheduled (silencioso: null si ya estaba)
     * como por registerNow() (que sobre esto agrega el rechazo explicito).
     */
    public function registerForMonth(FixedExpenseTemplate $template, int $year, int $month, ?float $amountOverride = null): ?Expense
    {
        if ($this->isRegisteredForMonth($template, $year, $month)) {
            return null;
        }

        $value = $amountOverride ?? (float) ($template->amount ?? 0);
        if ($value <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Esta plantilla no tiene un monto configurado - indica uno para registrar el gasto.',
            ]);
        }

        return $this->expenseService->create([
            'business_id' => $template->business_id,
            'date' => Carbon::create($year, $month, $template->day_of_month ?? 1)->toDateString(),
            'description' => $template->name,
            'value' => $value,
            'scope' => $template->scope,
            'type_id' => $template->expense_type_id,
            'fixed_expense_template_id' => $template->id,
        ]);
    }

    /** Version para el disparo manual desde la API: rechaza en vez de callar si el mes ya tiene su gasto. */
    public function registerNow(FixedExpenseTemplate $template, int $year, int $month, ?float $amountOverride = null): Expense
    {
        $expense = $this->registerForMonth($template, $year, $month, $amountOverride);

        if (! $expense) {
            throw ValidationException::withMessages([
                'template' => 'Ya se registró un gasto de esta plantilla para ese mes.',
            ]);
        }

        return $expense;
    }

    /**
     * Recordatorio mensual del pago ("se aproxima el pago del arriendo"): un
     * toggle, no un CRUD aparte. Si ya tiene uno pendiente lo quita
     * (desactivar), si no lo crea con recurrencia mensual sobre el propio
     * day_of_month de la plantilla. Devuelve el Reminder creado, o null si lo
     * que paso fue una desactivacion.
     */
    public function toggleReminder(User $user, FixedExpenseTemplate $template): ?Reminder
    {
        if (! $template->day_of_month) {
            throw ValidationException::withMessages([
                'template' => 'Esta plantilla no tiene un día del mes configurado.',
            ]);
        }

        $existing = $template->reminders()->where('status', Reminder::STATUS_PENDING)->first();
        if ($existing) {
            $existing->delete();

            return null;
        }

        $today = Carbon::today();
        $day = $template->day_of_month;
        $nextDue = $today->day <= $day
            ? Carbon::create($today->year, $today->month, $day)
            : Carbon::create($today->year, $today->month, $day)->addMonthNoOverflow();

        return $this->reminderService->create($template->business_id, $user->id, [
            'title' => 'Pagar '.$template->name,
            'due_date' => $nextDue->toDateString(),
            'recurrence' => 'monthly',
            'remindable_type' => FixedExpenseTemplate::class,
            'remindable_id' => $template->id,
        ]);
    }
}
