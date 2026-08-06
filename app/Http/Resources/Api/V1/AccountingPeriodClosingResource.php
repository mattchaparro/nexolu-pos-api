<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountingPeriodClosingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'month' => $this->month,
            'status' => $this->status,
            'notes' => $this->notes,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $this->whenLoaded('closedByUser', fn () => $this->closedByUser?->name),
        ];
    }
}
