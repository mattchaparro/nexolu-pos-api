<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashClosingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'date' => $this->date?->toDateString(),
            'total_sales' => $this->total_sales,
            'total_cash' => $this->total_cash,
            'total_other_methods' => $this->total_other_methods,
            'payment_breakdown' => $this->payment_breakdown,
            'opening_cash' => $this->opening_cash,
            'total_expenses' => $this->total_expenses,
            'expected_cash' => $this->expected_cash,
            'actual_cash' => $this->actual_cash,
            'base_for_next_day' => $this->base_for_next_day,
            'difference' => $this->difference,
            'closed_by' => $this->closed_by,
            'closed_by_user' => new UserResource($this->whenLoaded('closedByUser')),
            'created_via' => $this->created_via,
            // Solo el cierre del mismo dia calendario se puede deshacer - ver
            // CashClosingService::undoCashClosing.
            'can_undo' => $this->date?->toDateString() === now()->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
