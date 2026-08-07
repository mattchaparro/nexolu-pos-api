<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RemindSupplierVisitRequest;
use App\Http\Requests\Api\V1\StoreSupplierRequest;
use App\Http\Requests\Api\V1\UpdateSupplierRequest;
use App\Http\Resources\Api\V1\ReminderResource;
use App\Http\Resources\Api\V1\SupplierResource;
use App\Models\Reminder;
use App\Models\Supplier;
use App\Services\ReminderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SupplierController extends Controller
{
    public function __construct(private ReminderService $reminderService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Supplier::query()
            ->with(['reminders' => fn ($q) => $q->where('status', Reminder::STATUS_PENDING)])
            ->orderBy('name');

        if ($request->filled('search')) {
            $term = '%'.trim((string) $request->input('search')).'%';
            $query->where(function ($sub) use ($term) {
                $sub->where('name', 'like', $term)
                    ->orWhere('tax_id', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        return SupplierResource::collection($query->paginate(20)->withQueryString());
    }

    public function store(StoreSupplierRequest $request): SupplierResource
    {
        return new SupplierResource(Supplier::create($request->validated()));
    }

    public function show(Supplier $supplier): SupplierResource
    {
        return new SupplierResource($supplier);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $supplier->update($request->validated());

        return new SupplierResource($supplier->fresh());
    }

    public function destroy(Supplier $supplier): Response
    {
        $supplier->delete();

        return response()->noContent();
    }

    /**
     * Recordatorio de visita del proveedor ("mañana viene Postobón"). El
     * remindable se fija del proveedor de la ruta, no de un id que mande el
     * cliente en el payload.
     */
    public function remindVisit(RemindSupplierVisitRequest $request, Supplier $supplier): ReminderResource
    {
        $reminder = $this->reminderService->create($supplier->business_id, $request->user()->id, [
            'title' => 'Visita de '.$supplier->name,
            'due_date' => $request->validated('due_date'),
            'recurrence' => $request->validated('recurrence') ?? 'none',
            'end_date' => $request->validated('end_date'),
            'remindable_type' => Supplier::class,
            'remindable_id' => $supplier->id,
        ]);

        return new ReminderResource($reminder);
    }
}
