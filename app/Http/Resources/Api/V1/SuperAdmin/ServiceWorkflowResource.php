<?php

namespace App\Http\Resources\Api\V1\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceWorkflowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'stages_count' => $this->whenCounted('stages'),
            'businesses_count' => $this->whenCounted('businesses'),
            'stages' => ServiceWorkflowStageResource::collection($this->whenLoaded('stages')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
