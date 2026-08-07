<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LayawayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'status' => $this->status,
            'notes' => $this->notes,
            'total' => $this->total,
            'paid' => $this->paid,
            'balance' => $this->balance,
            'created_by_user_id' => $this->created_by_user_id,
            'cancelled_by_user_id' => $this->cancelled_by_user_id,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'items' => LayawayItemResource::collection($this->whenLoaded('items')),
            'payments' => LayawayPaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
