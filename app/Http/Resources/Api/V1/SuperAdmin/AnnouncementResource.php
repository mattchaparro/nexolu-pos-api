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
            'active' => $this->active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
