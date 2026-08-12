<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BulkUpdateProductsRequest;
use App\Services\BulkStockUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class BulkStockUpdateController extends Controller
{
    public function __construct(private BulkStockUpdateService $service) {}

    public function store(BulkUpdateProductsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $counts = $this->service->updateProducts($request->user(), $data['items'], $data['notes'] ?? null);

        if (array_sum($counts) === 0) {
            throw ValidationException::withMessages([
                'items' => 'Ningún producto cambió respecto a sus valores actuales.',
            ]);
        }

        return response()->json($counts);
    }
}
