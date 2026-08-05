<?php

namespace App\Capabilities\Clients;

use App\Capabilities\Capability;
use App\Models\Business;
use App\Models\User;
use App\Services\ClientService;

/**
 * Tool: crear_cliente (escritura). Igual que CreateExpenseCapability: el
 * IA Core solo llama aca despues de que el usuario confirmo el borrador.
 */
class CreateClientCapability implements Capability
{
    public function __construct(private ClientService $clientService) {}

    public function requiredPermission(): ?string
    {
        return 'clients.manage';
    }

    public function requiredFeature(): ?string
    {
        return 'clients';
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $client = $this->clientService->create([
            'name' => $arguments['nombre'],
            'phone' => $arguments['telefono'] ?? null,
            'email' => $arguments['email'] ?? null,
        ]);

        return [
            'id' => $client->id,
            'nombre' => $client->name,
            'telefono' => $client->phone,
            'email' => $client->email,
        ];
    }
}
