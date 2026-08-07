<?php

namespace App\Http\Resources\Api\V1\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'type' => $this->type,
            'to_email' => $this->to_email,
            'subject' => $this->subject,
            'status' => $this->status,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
