<?php

namespace App\Capabilities\Reminders;

use App\Capabilities\Capability;
use App\Models\Business;
use App\Models\User;
use App\Services\ReminderService;

/**
 * Tool: crear_recordatorio (escritura). El IA Core solo llama aca despues de
 * que el usuario confirmo el borrador.
 */
class CreateReminderCapability implements Capability
{
    public function __construct(private ReminderService $reminderService) {}

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
            'titulo' => ['required', 'string', 'max:150'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'recurrencia' => ['sometimes', 'nullable', 'in:none,daily,weekly,monthly,yearly'],
            'repetir_hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:fecha'],
            'nota' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        // Via ReminderService y no Reminder::create() para que
        // series_anchor_date quede igual que por cualquier otro canal.
        $reminder = $this->reminderService->create($business->id, $user->id, [
            'title' => $arguments['titulo'],
            'notes' => $arguments['nota'] ?? null,
            'due_date' => $arguments['fecha'],
            'recurrence' => $arguments['recurrencia'] ?? 'none',
            'end_date' => $arguments['repetir_hasta'] ?? null,
        ]);

        return [
            'id' => $reminder->id,
            'titulo' => $reminder->title,
            'fecha' => $reminder->due_date->toDateString(),
            'se_repite' => $reminder->recurrence !== 'none' ? $reminder->recurrence : null,
        ];
    }
}
