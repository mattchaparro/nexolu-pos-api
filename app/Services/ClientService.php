<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Unico punto de creacion/edicion de clientes. Extraido de ClientController
 * para que App\Capabilities\Clients\CreateClientCapability (invocada por
 * el Nexolu IA Core) reutilice exactamente la misma logica que el endpoint
 * HTTP normal, en vez de reimplementarla - incluyendo el limite del plan.
 */
class ClientService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Client
    {
        // El tope depende del plan y de si el negocio vende por internet
        // (ver Business::clientLimit), no de una constante unica: una tienda
        // online llena 50 fichas en semanas.
        $limit = Auth::user()?->business?->clientLimit() ?? Client::LIMIT_PER_BUSINESS;
        if (Client::count() >= $limit) {
            throw ValidationException::withMessages([
                'name' => 'Limite de '.$limit.' clientes alcanzado en el plan actual.',
            ]);
        }

        return Client::create($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Client $client, array $data): Client
    {
        $client->update($data);

        return $client->fresh();
    }
}
