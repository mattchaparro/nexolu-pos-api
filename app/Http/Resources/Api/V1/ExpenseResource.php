<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
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
            'date' => $this->date?->toDateString(),
            'description' => $this->description,
            'value' => $this->value,
            'scope' => $this->scope,
            'payment_method' => $this->payment_method,
            'type' => new ExpenseTypeResource($this->whenLoaded('type')),
            'linkable_type' => $this->linkable_type,
            'linkable_id' => $this->linkable_id,
        ];
    }
}
