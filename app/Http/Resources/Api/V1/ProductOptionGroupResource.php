<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductOptionGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'min_choices' => $this->min_choices,
            'max_choices' => $this->max_choices,
            'products' => $this->whenLoaded('products', fn () => $this->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()),
            'options' => $this->options->map(fn ($option) => [
                'id' => $option->id,
                'name' => $option->name,
                'extra_price' => number_format((float) $option->extra_price, 2, '.', ''),
                'ingredient_id' => $option->ingredient_id,
                'ingredient_quantity' => $option->ingredient_quantity === null ? null : (float) $option->ingredient_quantity,
                'is_active' => $option->is_active,
            ])->values(),
        ];
    }
}
