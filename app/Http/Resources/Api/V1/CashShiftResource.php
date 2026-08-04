<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashShiftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'opening_cash' => $this->opening_cash,
            'opening_note' => $this->opening_note,
            'counted_cash' => $this->counted_cash,
            'expected_cash' => $this->expected_cash,
            'difference' => $this->difference,
            'closing_note' => $this->closing_note,
            'payment_breakdown' => $this->payment_breakdown,
            'total_sales' => $this->total_sales,
            'total_cash' => $this->total_cash,
            'total_other_methods' => $this->total_other_methods,
            'total_expenses' => $this->total_expenses,
            'closed_by_user_id' => $this->closed_by_user_id,
            'closed_by_user' => new UserResource($this->whenLoaded('closedByUser')),
            'closed_via' => $this->closed_via,
            'closed_by_cash_closing_id' => $this->closed_by_cash_closing_id,
            'is_open' => $this->isOpen(),
            'is_from_a_previous_day' => $this->isFromAPreviousDay(),
        ];
    }
}
