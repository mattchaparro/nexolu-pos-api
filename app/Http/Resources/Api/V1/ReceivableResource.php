<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceivableResource extends JsonResource
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
            'sale_id' => $this->sale_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_identification' => $this->customer_identification,
            'amount' => $this->amount,
            'balance' => $this->balance,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'collected_by_user_id' => $this->collected_by_user_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
