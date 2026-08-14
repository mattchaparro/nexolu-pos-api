<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CollectReceivableRequest;
use App\Http\Resources\Api\V1\ReceivableResource;
use App\Models\Receivable;
use App\Services\ReceivableService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReceivableController extends Controller
{
    public function __construct(private ReceivableService $receivableService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Receivable::with('sale')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $term = $request->string('search');
            $query->where(function ($q) use ($term) {
                $q->where('customer_name', 'like', "%{$term}%")
                    ->orWhere('customer_phone', 'like', "%{$term}%")
                    ->orWhere('customer_identification', 'like', "%{$term}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->string('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->string('date_to'));
        }

        return ReceivableResource::collection($query->paginate(20)->withQueryString());
    }

    public function show(Receivable $receivable): ReceivableResource
    {
        return new ReceivableResource($receivable->load('sale'));
    }

    /**
     * Cards de resumen (Pendientes, Cobrados este mes) - sobre el conjunto
     * completo del negocio, no solo la pagina visible del listado paginado.
     * Mismo criterio que ProductController::summary().
     */
    public function summary(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;

        $pending = Receivable::where('business_id', $businessId)->where('status', 'pending');

        $collectedThisMonth = Receivable::where('business_id', $businessId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()]);

        return response()->json([
            'pending_count' => (clone $pending)->count(),
            'pending_amount' => (float) (clone $pending)->sum('balance'),
            'collected_this_month_count' => (clone $collectedThisMonth)->count(),
            'collected_this_month_amount' => (float) (clone $collectedThisMonth)->sum('amount'),
        ]);
    }

    public function collect(CollectReceivableRequest $request, Receivable $receivable): ReceivableResource
    {
        $data = $request->validated();

        $receivable = $this->receivableService->collect(
            $request->user(),
            $receivable,
            $data['payment_method'],
            $data
        );

        AuditLogger::log('receivable.collected', [
            'receivable_id' => $receivable->id,
            'sale_id' => $receivable->sale_id,
            'amount' => $receivable->amount,
        ]);

        return new ReceivableResource($receivable);
    }
}
