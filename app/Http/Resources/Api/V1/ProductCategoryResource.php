<?php

namespace App\Http\Resources\Api\V1;

use App\Support\CategoryIconResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductCategoryResource extends JsonResource
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
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'description' => $this->description,
            // Nombre de Material Icon (mismo vocabulario que el legacy) -
            // CategoryIconResolver solo evita devolverlo vacio.
            'icon' => CategoryIconResolver::resolve($this->icon),
        ];
    }
}
