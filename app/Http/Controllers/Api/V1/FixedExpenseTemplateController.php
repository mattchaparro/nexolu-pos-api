<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterFixedExpenseTemplateRequest;
use App\Http\Requests\Api\V1\StoreFixedExpenseTemplateRequest;
use App\Http\Requests\Api\V1\UpdateFixedExpenseTemplateRequest;
use App\Http\Resources\Api\V1\ExpenseResource;
use App\Http\Resources\Api\V1\FixedExpenseTemplateResource;
use App\Models\FixedExpenseTemplate;
use App\Models\Reminder;
use App\Services\FixedExpenseTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class FixedExpenseTemplateController extends Controller
{
    public function __construct(private FixedExpenseTemplateService $templates) {}

    public function index(): AnonymousResourceCollection
    {
        $templates = FixedExpenseTemplate::with([
            'expenseType',
            'reminders' => fn ($q) => $q->where('status', Reminder::STATUS_PENDING),
        ])
            ->orderByDesc('active')
            ->orderBy('day_of_month')
            ->orderBy('name')
            ->get();

        return FixedExpenseTemplateResource::collection($templates);
    }

    public function store(StoreFixedExpenseTemplateRequest $request): FixedExpenseTemplateResource
    {
        $template = FixedExpenseTemplate::create($request->validated());

        // refresh(), no fresh(): columnas con DEFAULT a nivel de BD que la
        // request no mando (active) quedan null en la instancia en memoria
        // hasta releerla - mismo bug ya visto y corregido en ProductService.
        // refresh() (no fresh()) porque preserva wasRecentlyCreated en la
        // MISMA instancia, que es lo que hace que Laravel devuelva 201 en
        // vez de 200 para un recurso recien creado.
        return new FixedExpenseTemplateResource($template->refresh()->load('expenseType'));
    }

    public function show(FixedExpenseTemplate $fixedExpenseTemplate): FixedExpenseTemplateResource
    {
        return new FixedExpenseTemplateResource(
            $fixedExpenseTemplate->load([
                'expenseType',
                'reminders' => fn ($q) => $q->where('status', Reminder::STATUS_PENDING),
            ])
        );
    }

    public function update(UpdateFixedExpenseTemplateRequest $request, FixedExpenseTemplate $fixedExpenseTemplate): FixedExpenseTemplateResource
    {
        $fixedExpenseTemplate->update($request->validated());

        return new FixedExpenseTemplateResource($fixedExpenseTemplate->fresh()->load('expenseType'));
    }

    public function destroy(FixedExpenseTemplate $fixedExpenseTemplate): Response
    {
        $fixedExpenseTemplate->delete();

        return response()->noContent();
    }

    public function registerNow(RegisterFixedExpenseTemplateRequest $request, FixedExpenseTemplate $fixedExpenseTemplate): ExpenseResource
    {
        $expense = $this->templates->registerNow(
            $fixedExpenseTemplate,
            (int) $request->validated('year'),
            (int) $request->validated('month'),
            $request->validated('amount') !== null ? (float) $request->validated('amount') : null,
        );

        return new ExpenseResource($expense->load('type'));
    }

    public function toggleReminder(Request $request, FixedExpenseTemplate $fixedExpenseTemplate): JsonResponse
    {
        $reminder = $this->templates->toggleReminder($request->user(), $fixedExpenseTemplate);

        return response()->json(['active' => $reminder !== null]);
    }
}
