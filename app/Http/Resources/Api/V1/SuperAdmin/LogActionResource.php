<?php

namespace App\Http\Resources\Api\V1\SuperAdmin;

use App\Support\AuditActionDictionary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LogActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            // Igual que el recurso de negocio: 'tab.item_removed' no se le puede
            // mostrar a nadie tal cual, y traducirlo en el front obligaria a
            // duplicar el diccionario del backend.
            'action_label' => AuditActionDictionary::label($this->action),
            'business_id' => $this->business_id,
            'business' => $this->whenLoaded('business', fn () => $this->business ? ['id' => $this->business->id, 'name' => $this->business->name] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email] : null),
            'details' => $this->details,
            'ip' => $this->ip,
            'url' => $this->url,
            'method' => $this->method,
            // Solo el panel de superadmin ve esta marca: la auditoria del
            // negocio filtra estas filas a proposito (ver
            // AuditLogQuery::forBusiness), pero desde aca importa distinguir
            // lo que hizo el dueño de lo que hizo soporte en su nombre.
            'impersonated_by_superadmin_id' => $this->details['impersonated_by_superadmin_id'] ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
