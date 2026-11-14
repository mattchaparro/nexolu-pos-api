<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PrintAgent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un equipo con el agente de impresion, como lo ve el admin en Configuracion
 * › Impresoras. Nunca incluye el token ni el hash del codigo.
 *
 * @mixin PrintAgent
 */
class PrintAgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isPairing = ! $this->isPaired() && $this->pairing_expires_at?->isFuture();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'branch_id' => $this->branch_id,
            // paired | pairing (esperando el codigo) | unpaired
            'status' => $this->isPaired() ? 'paired' : ($isPairing ? 'pairing' : 'unpaired'),
            'is_online' => $this->isOnline(),
            'version' => $this->version,
            'reported_printers' => $this->reported_printers ?? [],
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'pairing_expires_at' => $isPairing ? $this->pairing_expires_at->toIso8601String() : null,
        ];
    }
}
