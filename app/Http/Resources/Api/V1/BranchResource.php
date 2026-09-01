<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branch */
class BranchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'is_main' => (bool) $this->is_main,
            'is_active' => (bool) $this->is_active,
            'address' => $this->address,
            'phone' => $this->phone,
            'whatsapp_number' => $this->whatsapp_number,
            // Resuelto, no crudo: null en la sede significa "el del negocio",
            // y el front no tiene por que replicar esa resolucion.
            'invoice_prefix' => $this->invoicePrefix(),
        ];
    }
}
