<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Reminder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'name' => $this->name,
            'tax_id' => $this->tax_id,
            'phone' => $this->phone,
            'address' => $this->address,
            'notes' => $this->notes,
            'has_pending_visit_reminder' => $this->whenLoaded(
                'reminders',
                fn () => $this->reminders->contains(fn (Reminder $r) => $r->status === Reminder::STATUS_PENDING)
            ),
            // Fecha de la proxima visita pendiente (la mas cercana), para el
            // badge de la lista - ver Suppliers/Index.vue del legacy, que
            // muestra la fecha, no solo un booleano.
            'next_visit_reminder_due_date' => $this->whenLoaded(
                'reminders',
                fn () => $this->reminders
                    ->filter(fn (Reminder $r) => $r->status === Reminder::STATUS_PENDING)
                    ->sortBy('due_date')
                    ->first()
                    ?->due_date?->toDateString()
            ),
        ];
    }
}
