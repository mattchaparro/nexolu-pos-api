<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CollectReceivableRequest;
use App\Http\Resources\Api\V1\ReceivableResource;
use App\Models\Receivable;
use App\Services\ReceivableService;
use App\Support\AuditLogger;
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

        return ReceivableResource::collection($query->paginate(20)->withQueryString());
    }

    public function show(Receivable $receivable): ReceivableResource
    {
        return new ReceivableResource($receivable->load('sale'));
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
