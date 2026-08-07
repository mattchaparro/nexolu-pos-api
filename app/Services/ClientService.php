<?php

namespace App\Services;

use App\Models\Client;
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
        if (Client::count() >= Client::LIMIT_PER_BUSINESS) {
            throw ValidationException::withMessages([
                'name' => 'Limite de '.Client::LIMIT_PER_BUSINESS.' clientes alcanzado en el plan actual.',
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
