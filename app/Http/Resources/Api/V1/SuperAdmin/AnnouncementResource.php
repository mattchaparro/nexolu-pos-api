<?php

namespace App\Http\Resources\Api\V1\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'cta_label' => $this->cta_label,
            'cta_url' => $this->cta_url,
            'audience' => $this->audience,
            'business_scope' => $this->business_scope,
            'business_ids' => $this->business_ids,
            'active' => $this->active,
            // Cuantos usuarios ya lo vieron y lo cerraron en el POS.
            'seen_count' => $this->whenCounted('dismissals'),
            'whatsapp_requested' => $this->whatsapp_requested_at !== null,
            'whatsapp_sent' => (int) $this->whatsapp_sent,
            'whatsapp_failed' => (int) $this->whatsapp_failed,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
