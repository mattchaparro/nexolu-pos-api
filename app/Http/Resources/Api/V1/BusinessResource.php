<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug,
            'owner_name' => $this->owner_name,
            'phone' => $this->phone,
            'whatsapp_number' => $this->whatsapp_number,
            'instagram_handle' => $this->instagram_handle,
            'tiktok_handle' => $this->tiktok_handle,
            'nit' => $this->nit,
            'address' => $this->address,
            'logo_path' => $this->logo_path,
            'ticket_paper_width' => $this->ticket_paper_width,
            'invoice_prefix' => $this->invoice_prefix,
            'ticket_header_tagline' => $this->ticket_header_tagline,
            'ticket_thanks_message' => $this->ticket_thanks_message,
            'ticket_footer_text' => $this->ticket_footer_text,
            'delivery_enabled' => $this->delivery_enabled,
            'delivery_fee' => $this->delivery_fee,
            'charges' => $this->chargesConfig(),
            'payment_methods' => $this->paymentMethods(),
            'low_stock_alert_threshold' => $this->low_stock_alert_threshold,
            'low_stock_email_enabled' => $this->low_stock_email_enabled,
            'low_stock_email' => $this->low_stock_email,
            'feature_flags' => $this->feature_flags,
            'subscription_plan' => $this->subscription_plan,
            'subscription_status' => $this->subscriptionStatus(),
            'active' => $this->active,
        ];
    }
}
