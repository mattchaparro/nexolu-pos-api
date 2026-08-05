<?php

namespace App\Http\Resources\Api\V1\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceWorkflowStageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'label' => $this->label,
            'color' => $this->color,
            'sort_order' => $this->sort_order,
            'is_initial' => $this->is_initial,
            'actions' => $this->actions,
        ];
    }
}
