<?php

namespace App\Services;

use App\Models\Reminder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ReminderService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $businessId, int $userId, array $data): Reminder
    {
        return Reminder::create([
            'business_id' => $businessId,
            'created_by_user_id' => $userId,
            'title' => trim($data['title']),
            'notes' => isset($data['notes']) ? trim((string) $data['notes']) : null,
            'due_date' => $data['due_date'],
            // Sin hora, no hay como avisar por WhatsApp: si no viene notify_time
            // el aviso queda apagado aunque lo hayan marcado.
            'notify_time' => $data['notify_time'] ?? null,
            'notify_whatsapp' => ! empty($data['notify_whatsapp']) && ! empty($data['notify_time']),
            // El ancla nace igual a due_date - ver el comentario en el modelo:
            // solo complete() la vuelve a mover, nunca postpone().
            'series_anchor_date' => $data['due_date'],
            'recurrence' => $data['recurrence'] ?? 'none',
            'end_date' => $data['end_date'] ?? null,
            'status' => Reminder::STATUS_PENDING,
            'remindable_type' => $data['remindable_type'] ?? null,
            'remindable_id' => $data['remindable_id'] ?? null,
        ]);
    }

    /**
     * Marca un recordatorio como hecho.
     *
     * Si es recurrente, NO lo cierra: avanza su propia due_date al siguiente
     * ciclo y lo deja pendiente otra vez - una sola fila representa el
     * recordatorio recurrente completo, sin generar una fila por ciclo.
     *
     * El siguiente ciclo se calcula desde series_anchor_date, NO desde
     * due_date: si el recordatorio fue pospuesto (due_date movido a mano), el
     * ancla se queda donde estaba, para que posponer una ocurrencia no
     * arrastre la cadencia completa de las siguientes.
     *
     * Si tiene end_date y el siguiente ciclo ya lo pasaria, la serie termino:
     * se cierra como cualquier recordatorio de fecha unica.
     */
    public function complete(Reminder $reminder): Reminder
    {
        if ($reminder->isRecurring()) {
            $anchor = $reminder->series_anchor_date ?? $reminder->due_date;
            $next = $this->nextDate($anchor, $reminder->recurrence);

            if ($reminder->end_date && $next->greaterThan($reminder->end_date)) {
                $reminder->update([
                    'status' => Reminder::STATUS_DONE,
                    'completed_at' => now(),
                    'last_completed_at' => now(),
                ]);
            } else {
                $reminder->update([
                    'due_date' => $next,
                    'series_anchor_date' => $next,
                    'status' => Reminder::STATUS_PENDING,
                    'last_completed_at' => now(),
                ]);
            }
        } else {
            $reminder->update([
                'status' => Reminder::STATUS_DONE,
                'completed_at' => now(),
            ]);
        }

        return $reminder->fresh();
    }

    /**
     * Pospone un recordatorio a una fecha nueva, sin tocar su recurrencia.
     *
     * A proposito NO toca series_anchor_date: posponer es "esta vez si, pero
     * mas tarde", no "mueve la cadencia completa".
     */
    public function postpone(Reminder $reminder, string $newDate): Reminder
    {
        if ($reminder->status !== Reminder::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'reminder' => 'Solo se pueden posponer recordatorios pendientes.',
            ]);
        }

        $reminder->update(['due_date' => $newDate]);

        return $reminder->fresh();
    }

    public function nextDate(Carbon $from, string $recurrence): Carbon
    {
        $from = $from->copy();

        return match ($recurrence) {
            'daily' => $from->addDay(),
            'weekly' => $from->addWeek(),
            'monthly' => $from->addMonthNoOverflow(),
            'yearly' => $from->addYearNoOverflow(),
            default => throw ValidationException::withMessages([
                'recurrence' => 'Un recordatorio sin recurrencia no tiene siguiente fecha.',
            ]),
        };
    }
}
