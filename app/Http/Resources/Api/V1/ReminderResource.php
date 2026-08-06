<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReminderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'created_by_user_id' => $this->created_by_user_id,
            'title' => $this->title,
            'notes' => $this->notes,
            'due_date' => $this->due_date?->toDateString(),
            'notify_time' => $this->notify_time,
            'notify_whatsapp' => $this->notify_whatsapp,
            'recurrence' => $this->recurrence,
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status,
            'is_recurring' => $this->isRecurring(),
            'is_overdue' => $this->isOverdue(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'remindable_type' => $this->remindable_type,
            'remindable_id' => $this->remindable_id,
            'created_by' => new UserResource($this->whenLoaded('createdBy')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
