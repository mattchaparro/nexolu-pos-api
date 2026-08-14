<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExpenseRequest;
use App\Http\Requests\Api\V1\UpdateExpenseRequest;
use App\Http\Resources\Api\V1\ExpenseResource;
use App\Models\Expense;
use App\Services\ExpenseService;
use App\Services\ReminderService;
use App\Support\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ExpenseController extends Controller
{
    /** Claves de recordatorio del payload: no son de Expense, se separan antes de pasarlas a ExpenseService::create(). */
    private const REMINDER_KEYS = ['reminder_title', 'reminder_date', 'reminder_recurrence', 'reminder_end_date', 'reminder_notes'];

    public function __construct(
        private ExpenseService $expenseService,
        private ReminderService $reminderService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Expense::with('type')->orderByDesc('date')->orderByDesc('id');

        if ($request->filled('date')) {
            $query->whereDate('date', $request->input('date'));
        } elseif ($request->filled('month')) {
            $year = $request->integer('year') ?: Carbon::now()->year;
            $month = $request->integer('month');
            $start = Carbon::create($year, $month)->startOfMonth()->toDateString();
            $end = Carbon::create($year, $month)->endOfMonth()->toDateString();
            $query->whereBetween('date', [$start, $end]);
        } elseif ($request->filled('year')) {
            $year = $request->integer('year');
            $query->whereBetween('date', [
                Carbon::create($year)->startOfYear()->toDateString(),
                Carbon::create($year)->endOfYear()->toDateString(),
            ]);
        }

        if ($request->filled('type_id')) {
            $query->where('type_id', $request->integer('type_id'));
        }

        if ($request->filled('search')) {
            $query->where('description', 'like', '%'.$request->input('search').'%');
        }

        return ExpenseResource::collection($query->paginate(20)->withQueryString());
    }

    public function store(StoreExpenseRequest $request): ExpenseResource
    {
        $data = [...$request->defaults(), ...$request->validated()];
        $reminderData = array_intersect_key($data, array_flip(self::REMINDER_KEYS));
        $expense = $this->expenseService->create(array_diff_key($data, array_flip(self::REMINDER_KEYS)));

        if (! empty($reminderData['reminder_date'])) {
            $this->reminderService->create($request->user()->business_id, $request->user()->id, [
                'title' => ($reminderData['reminder_title'] ?? null) ?: ('Gasto: '.$data['description']),
                'due_date' => $reminderData['reminder_date'],
                'recurrence' => $reminderData['reminder_recurrence'] ?? 'none',
                'end_date' => $reminderData['reminder_end_date'] ?? null,
                'notes' => $reminderData['reminder_notes'] ?? null,
                'remindable_type' => Expense::class,
                'remindable_id' => $expense->id,
            ]);
        }

        AuditLogger::log('expense.created', [
            'expense_id' => $expense->id,
            'description' => $expense->description,
            'value' => $expense->value,
        ]);

        return new ExpenseResource($expense->load('type'));
    }

    public function show(Expense $expense): ExpenseResource
    {
        return new ExpenseResource($expense->load('type'));
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): ExpenseResource
    {
        $expense = $this->expenseService->update($expense, $request->validated());

        AuditLogger::log('expense.updated', [
            'expense_id' => $expense->id,
            'description' => $expense->description,
            'value' => $expense->value,
        ]);

        return new ExpenseResource($expense);
    }

    public function destroy(Expense $expense): Response
    {
        AuditLogger::log('expense.deleted', [
            'expense_id' => $expense->id,
            'description' => $expense->description,
            'value' => $expense->value,
        ]);

        $expense->delete();

        return response()->noContent();
    }
}
