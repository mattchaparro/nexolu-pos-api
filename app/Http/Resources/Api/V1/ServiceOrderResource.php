<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceOrderResource extends JsonResource
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
            'appointment_id' => $this->appointment_id,
            'product_id' => $this->product_id,
            'user_id' => $this->user_id,
            'stage_id' => $this->stage_id,
            'service_name' => $this->service_name,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'balance' => $this->balance,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'notes' => $this->notes,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'items' => ServiceOrderItemResource::collection($this->whenLoaded('items')),
            'payments' => ServicePaymentResource::collection($this->whenLoaded('payments')),
            'client' => new ClientResource($this->whenLoaded('client')),
            'stage' => new ServiceWorkflowStageResource($this->whenLoaded('stage')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
