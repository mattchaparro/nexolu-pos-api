<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'email' => $this->email,
            'username' => $this->username,
            'cellphone' => $this->cellphone,
            'is_active' => $this->is_active,
            'is_business_owner' => $this->is_business_owner,
            'business_id' => $this->business_id,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            // getAllPermissions() incluye los heredados por rol; con
            // getPermissionNames() un admin devolveria [] porque no tiene
            // permisos directos (solo los del rol admin, que hereda todo).
            'permissions' => $this->whenLoaded('roles', fn () => $this->getAllPermissions()->pluck('name')->values()),
            // N+1 aceptado a proposito: esta pantalla es un panel admin
            // paginado a 20-30 filas, no una ruta de alto trafico - una
            // consulta extra por fila no justifica precalcularlo en bloque.
            'last_active_at' => $this->lastActiveAt()?->toIso8601String(),
        ];
    }
}
