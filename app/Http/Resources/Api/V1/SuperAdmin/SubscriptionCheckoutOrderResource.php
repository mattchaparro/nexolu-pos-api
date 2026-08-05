<?php

namespace App\Http\Resources\Api\V1\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionCheckoutOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business' => $this->whenLoaded('business', fn () => ['id' => $this->business->id, 'name' => $this->business->name]),
            'order_key' => $this->order_key,
            'amount_cop' => $this->amount_cop,
            'subscription_days' => $this->subscription_days,
            'status' => $this->status,
            'provider' => $this->provider,
            'provider_order_id' => $this->provider_order_id,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
