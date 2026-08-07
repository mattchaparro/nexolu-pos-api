<?php

namespace App\Http\Resources\Api\V1\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class BusinessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'owner_name' => $this->owner_name,
            'phone' => $this->phone,
            'nit' => $this->nit,
            'address' => $this->address,
            'active' => $this->active,
            'ai_chat_blocked' => $this->ai_chat_blocked,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'paid_until' => $this->paid_until?->toIso8601String(),
            'subscription_plan' => $this->subscription_plan,
            'subscription_status' => $this->subscriptionStatus(),
            'days_remaining' => $this->daysRemaining(),
            'custom_price_cop' => $this->custom_price_cop,
            'feature_flags' => $this->feature_flags,
            'crm_notes' => $this->crm_notes,
            'crm_next_follow_up_at' => $this->crm_next_follow_up_at?->toIso8601String(),
            'users_count' => $this->whenCounted('users'),
            'products_count' => $this->whenCounted('products'),
            'sales_count' => $this->whenCounted('sales'),
            'product_categories_count' => $this->whenCounted('productCategories'),
            'admin_user' => $this->when(isset($this->admin_user_id), fn () => [
                'id' => $this->admin_user_id,
                'name' => $this->admin_user_name,
            ]),
            'last_activity_at' => $this->when(isset($this->last_activity_at), fn () => Carbon::parse($this->last_activity_at)->toIso8601String()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
