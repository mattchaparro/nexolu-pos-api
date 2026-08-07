<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Reminder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FixedExpenseTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'name' => $this->name,
            'amount' => $this->amount,
            'expense_type' => new ExpenseTypeResource($this->whenLoaded('expenseType')),
            'active' => $this->active,
            'scope' => $this->scope,
            'day_of_month' => $this->day_of_month,
            'registered_this_month' => $this->registeredThisMonth(),
            'has_active_reminder' => $this->whenLoaded(
                'reminders',
                fn () => $this->reminders->contains(fn (Reminder $r) => $r->status === Reminder::STATUS_PENDING)
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
