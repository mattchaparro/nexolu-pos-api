<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
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
            'user_id' => $this->user_id,
            'invoice_number' => $this->invoice_number,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'total' => $this->total,
            'cart_discount_id' => $this->cart_discount_id,
            'cart_discount_amount' => $this->cart_discount_amount,
            'service_charge_amount' => $this->service_charge_amount,
            'ipoconsumo_amount' => $this->ipoconsumo_amount,
            'is_delivery' => $this->is_delivery,
            'delivery_fee' => $this->delivery_fee,
            'is_non_revenue' => $this->is_non_revenue,
            'non_revenue_reason' => $this->non_revenue_reason,
            'is_credit' => $this->is_credit,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_identification' => $this->customer_identification,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
