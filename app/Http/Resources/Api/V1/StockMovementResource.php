<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            // product_id apunta siempre al producto padre, asi que sin esto
            // el historial de un producto con variantes no deja distinguir
            // que talla movio cada linea.
            'product_variant_id' => $this->product_variant_id,
            'ingredient_id' => $this->ingredient_id,
            'type' => $this->type,
            'quantity' => $this->quantity,
            'unit_cost_cop' => $this->unit_cost_cop,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'reason' => $this->whenLoaded('reason', fn () => [
                'id' => $this->reason->id,
                'code' => $this->reason->code,
                'label' => $this->reason->label,
            ]),
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
