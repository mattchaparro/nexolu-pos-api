<?php

namespace App\Http\Resources\Api\V1\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'subject' => $this->subject,
            'fields' => $this->fields,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
