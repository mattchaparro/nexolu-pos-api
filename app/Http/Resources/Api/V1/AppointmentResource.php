<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'client_id' => $this->client_id,
            'product_id' => $this->product_id,
            'user_id' => $this->user_id,
            'client_name' => $this->client_name,
            'client_phone' => $this->client_phone,
            'client_email' => $this->client_email,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'status' => $this->status,
            'status_label' => $this->status_label,
            'notes' => $this->notes,
            'client' => new ClientResource($this->whenLoaded('client')),
            'service' => new ProductResource($this->whenLoaded('service')),
            'staff' => new UserResource($this->whenLoaded('staff')),
            'service_order' => new ServiceOrderResource($this->whenLoaded('serviceOrder')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
