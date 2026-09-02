<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscountResource extends JsonResource
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
            'business_id' => $this->business_id,
            'name' => $this->name,
            'type' => $this->type,
            'value' => $this->value,
            'scope' => $this->scope,
            // Con codigo es un CUPON de la tienda: lo redime el comprador
            // escribiendolo. Sin codigo es un descuento del mostrador, que
            // el cajero elige de una lista.
            'code' => $this->code,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'max_uses' => $this->max_uses,
            'used_count' => (int) $this->used_count,
            'min_order_amount' => $this->min_order_amount !== null ? (float) $this->min_order_amount : null,
            'product' => new ProductResource($this->whenLoaded('product')),
            'is_active' => $this->is_active,
        ];
    }
}
