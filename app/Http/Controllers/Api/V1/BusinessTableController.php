<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBusinessTableRequest;
use App\Http\Requests\Api\V1\UpdateBusinessTableRequest;
use App\Http\Resources\Api\V1\BusinessTableResource;
use App\Models\BusinessTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BusinessTableController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return BusinessTableResource::collection(
            BusinessTable::with('openSale')->orderBy('number')->get()
        );
    }

    public function store(StoreBusinessTableRequest $request): BusinessTableResource
    {
        $data = $request->validated();

        // Auto-numera al final si no se especifica, como el legacy.
        if (! isset($data['number'])) {
            $data['number'] = (int) BusinessTable::max('number') + 1;
        }

        $table = BusinessTable::create($data);

        // refresh(): "is_active" tiene DEFAULT 1 en BD; si el request no lo
        // manda, queda null en memoria hasta releer la fila.
        return new BusinessTableResource($table->refresh());
    }

    public function show(BusinessTable $table): BusinessTableResource
    {
        return new BusinessTableResource($table->load('openSale'));
    }

    // El toggle activo/inactivo del legacy se cubre aqui con is_active en el body.
    public function update(UpdateBusinessTableRequest $request, BusinessTable $table): BusinessTableResource
    {
        $table->update($request->validated());

        return new BusinessTableResource($table->fresh());
    }

    public function destroy(BusinessTable $table): Response|JsonResponse
    {
        if ($table->openSale()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: la mesa tiene una cuenta abierta.',
            ], 422);
        }

        $table->delete();

        return response()->noContent();
    }
}
