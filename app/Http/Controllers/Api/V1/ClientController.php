<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreClientRequest;
use App\Http\Requests\Api\V1\UpdateClientRequest;
use App\Http\Resources\Api\V1\ClientResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Client::query()->orderBy('name');

        if ($request->filled('search')) {
            $term = '%'.trim((string) $request->input('search')).'%';
            $query->where(function ($sub) use ($term) {
                $sub->where('name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        return ClientResource::collection($query->paginate(25)->withQueryString());
    }

    public function store(StoreClientRequest $request): ClientResource
    {
        if (Client::count() >= Client::LIMIT_PER_BUSINESS) {
            throw ValidationException::withMessages([
                'name' => 'Limite de '.Client::LIMIT_PER_BUSINESS.' clientes alcanzado en el plan actual.',
            ]);
        }

        $client = Client::create($request->validated());

        return new ClientResource($client);
    }

    public function show(Client $client): ClientResource
    {
        return new ClientResource($client);
    }

    public function update(UpdateClientRequest $request, Client $client): ClientResource
    {
        $client->update($request->validated());

        return new ClientResource($client->fresh());
    }

    public function destroy(Client $client): Response
    {
        $client->delete();

        return response()->noContent();
    }

    /**
     * Lightweight autocomplete search (e.g. for the sale/appointment forms).
     */
    public function search(Request $request): JsonResponse
    {
        $term = '%'.trim((string) $request->input('q', '')).'%';

        $clients = Client::query()
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term);
            })
            ->limit(10)
            ->get(['id', 'name', 'phone', 'email']);

        return response()->json($clients);
    }
}
