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
            'product' => new ProductResource($this->whenLoaded('product')),
            'is_active' => $this->is_active,
        ];
    }
}
