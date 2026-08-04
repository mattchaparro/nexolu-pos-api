<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'supplier_id' => $this->supplier_id,
            'purchased_at' => $this->purchased_at?->toDateString(),
            'invoice_number' => $this->invoice_number,
            'notes' => $this->notes,
            'payment_status' => $this->payment_status,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'total' => $this->total,
            'paid' => $this->paid,
            'balance' => $this->balance,
            'user_id' => $this->user_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'lines' => PurchaseLineResource::collection($this->whenLoaded('lines')),
            'payments' => PurchasePaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
