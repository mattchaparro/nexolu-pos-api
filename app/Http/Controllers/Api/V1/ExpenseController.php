<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExpenseRequest;
use App\Http\Requests\Api\V1\UpdateExpenseRequest;
use App\Http\Resources\Api\V1\ExpenseResource;
use App\Models\Expense;
use App\Services\ExpenseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ExpenseController extends Controller
{
    public function __construct(private ExpenseService $expenseService) {}

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
        $expense = $this->expenseService->create([
            ...$request->defaults(),
            ...$request->validated(),
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

        return new ExpenseResource($expense);
    }

    public function destroy(Expense $expense): Response
    {
        $expense->delete();

        return response()->noContent();
    }
}
