<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->first() ?? 'employee');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => (bool) $this->is_active,
            'is_business_owner' => (bool) $this->is_business_owner,
            'role' => $role,
            // Empleados llevan permisos DIRECTOS al usuario (getDirectPermissions).
            // Los admin heredan TODOS los permisos via el rol; devolverlos por
            // separado confundiria en la UI (aparecerian como "editables por checkbox").
            'permissions' => $this->when(
                $this->hasRole('employee'),
                fn () => $this->getDirectPermissions()->pluck('name')->values()
            ),
            'last_active_at' => $this->lastActiveAt()?->toIso8601String(),
        ];
    }
}
