<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchasePaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'notes' => $this->notes,
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
