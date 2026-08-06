<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'ingredient' => new IngredientResource($this->whenLoaded('ingredient')),
            'quantity' => $this->quantity,
            'unit_cost_cop' => $this->unit_cost_cop,
            'line_total_cop' => $this->line_total_cop,
            'notes' => $this->notes,
        ];
    }
}
