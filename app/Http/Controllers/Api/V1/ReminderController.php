<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PostponeReminderRequest;
use App\Http\Requests\Api\V1\StoreReminderRequest;
use App\Http\Resources\Api\V1\ReminderResource;
use App\Models\Appointment;
use App\Models\Reminder;
use App\Services\ReminderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReminderController extends Controller
{
    public function __construct(private ReminderService $reminderService) {}

    /**
     * @return array<string, AnonymousResourceCollection>
     */
    public function index(): array
    {
        // Los recordatorios de cita (2h antes, ver AppointmentService)
        // los genera el sistema solo para trackear su propio envio por
        // WhatsApp al cliente - no son una tarea del staff, asi que no
        // deben aparecer en el Planificador (a diferencia de los otros 4
        // hooks de remindable: Compra/Proveedor/Gasto/Plantilla de gasto
        // fijo, esos si son tareas reales del negocio).
        $excludeAppointmentReminders = fn (Builder $q) => $q->whereNull('remindable_type')->orWhere('remindable_type', '!=', Appointment::class);

        $pending = Reminder::query()
            ->where('status', Reminder::STATUS_PENDING)
            ->where($excludeAppointmentReminders)
            ->orderBy('due_date')
            ->get();

        $completed = Reminder::query()
            ->where('status', Reminder::STATUS_DONE)
            ->where($excludeAppointmentReminders)
            ->orderByDesc('completed_at')
            ->limit(20)
            ->get();

        return [
            'pending' => ReminderResource::collection($pending),
            'completed' => ReminderResource::collection($completed),
        ];
    }

    public function store(StoreReminderRequest $request): ReminderResource
    {
        $reminder = $this->reminderService->create(
            $request->user()->business_id,
            $request->user()->id,
            $request->validated(),
        );

        return new ReminderResource($reminder);
    }

    public function destroy(Reminder $reminder): JsonResponse
    {
        $reminder->delete();

        return response()->json(status: 204);
    }

    public function complete(Reminder $reminder): ReminderResource
    {
        return new ReminderResource($this->reminderService->complete($reminder));
    }

    public function postpone(PostponeReminderRequest $request, Reminder $reminder): ReminderResource
    {
        return new ReminderResource($this->reminderService->postpone($reminder, $request->validated('due_date')));
    }
}
