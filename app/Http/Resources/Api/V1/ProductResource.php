<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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
            'category' => new ProductCategoryResource($this->whenLoaded('category')),
            'name' => $this->name,
            'description' => $this->description,
            'how_to_use' => $this->how_to_use,
            'price' => $this->price,
            'cost_price' => $this->cost_price,
            'stock' => $this->stock,
            'low_stock_alert_threshold' => $this->low_stock_alert_threshold,
            'track_stock' => $this->track_stock,
            'is_single_sale' => $this->is_single_sale,
            'is_service' => $this->is_service,
            'price_varies_at_sale' => $this->price_varies_at_sale,
            'duration_minutes' => $this->duration_minutes,
            'sku' => $this->sku,
            'image' => $this->image,
            'is_active' => $this->is_active,
            'ingredients' => IngredientResource::collection($this->whenLoaded('ingredients')),
            'has_recipe' => $this->whenLoaded('ingredients', fn () => $this->ingredients->isNotEmpty()),
        ];
    }
}
