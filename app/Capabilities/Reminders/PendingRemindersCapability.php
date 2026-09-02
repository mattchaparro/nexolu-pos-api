<?php

namespace App\Capabilities\Reminders;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Models\Business;
use App\Models\Reminder;
use App\Models\User;

/** Tool: recordatorios_pendientes. Que hay pendiente en el planificador. */
class PendingRemindersCapability implements Capability
{
    use CapsRows;

    public function requiredPermission(): ?string
    {
        return 'reminders.manage';
    }

    public function requiredFeature(): ?string
    {
        return 'reminders';
    }

    public function rules(): array
    {
        return [
            'dias' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:90'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        $query = Reminder::query()->where('status', 'pending');

        // El filtro es solo un techo: los vencidos entran siempre, porque son
        // justo los que hay que ver.
        if (! empty($arguments['dias'])) {
            $query->whereDate('due_date', '<=', now()->addDays((int) $arguments['dias'])->toDateString());
        }

        $reminders = $query->orderBy('due_date')->limit(self::MAX_ROWS)->get();

        $today = now()->startOfDay();

        $rows = $reminders->map(function (Reminder $reminder) use ($today) {
            $dueDate = $reminder->due_date->copy()->startOfDay();

            return [
                'titulo' => $reminder->title,
                'nota' => $reminder->notes,
                'fecha' => $dueDate->toDateString(),
                // Positivo si es en el futuro, negativo si ya vencio.
                'dias_desde_hoy' => (int) $today->diffInDays($dueDate, false),
                'se_repite' => $reminder->recurrence !== 'none' ? $reminder->recurrence : null,
                'vencido' => $dueDate->lt($today),
            ];
        })->values()->all();

        return [
            'total_pendientes' => count($rows),
            'recordatorios' => $rows,
        ];
    }
}
