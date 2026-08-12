<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BulkUpdateIngredientsRequest;
use App\Services\BulkStockUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class IngredientBulkStockUpdateController extends Controller
{
    public function __construct(private BulkStockUpdateService $service) {}

    public function store(BulkUpdateIngredientsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $counts = $this->service->updateIngredients($request->user(), $data['items'], $data['notes'] ?? null);

        if (array_sum($counts) === 0) {
            throw ValidationException::withMessages([
                'items' => 'Ningún ingrediente cambió respecto a sus valores actuales.',
            ]);
        }

        return response()->json($counts);
    }
}
