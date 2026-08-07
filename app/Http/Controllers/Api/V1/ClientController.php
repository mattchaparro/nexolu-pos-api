<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreClientRequest;
use App\Http\Requests\Api\V1\UpdateClientRequest;
use App\Http\Resources\Api\V1\ClientResource;
use App\Models\Client;
use App\Services\ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ClientController extends Controller
{
    public function __construct(private ClientService $clientService) {}

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
        $client = $this->clientService->create($request->validated());

        return new ClientResource($client);
    }

    public function show(Client $client): ClientResource
    {
        return new ClientResource($client);
    }

    public function update(UpdateClientRequest $request, Client $client): ClientResource
    {
        $client = $this->clientService->update($client, $request->validated());

        return new ClientResource($client);
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
