<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IngredientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'name' => $this->name,
            'unit' => $this->unit,
            'stock' => $this->stock,
            'cost_price' => $this->cost_price,
            'min_stock' => $this->min_stock,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            // Solo presente cuando este recurso viene de la relacion
            // Product::ingredients() (pivot ingredient_product cargado).
            'quantity' => $this->whenPivotLoaded('ingredient_product', fn () => $this->pivot->quantity),
        ];
    }
}
