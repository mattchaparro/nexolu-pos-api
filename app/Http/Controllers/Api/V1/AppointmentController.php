<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RescheduleAppointmentRequest;
use App\Http\Requests\Api\V1\StoreAppointmentRequest;
use App\Http\Requests\Api\V1\UpdateAppointmentRequest;
use App\Http\Requests\Api\V1\UpdateAppointmentStatusRequest;
use App\Http\Resources\Api\V1\AppointmentResource;
use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentController extends Controller
{
    public function __construct(private AppointmentService $appointmentService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Appointment::with(['client', 'service', 'staff', 'serviceOrder.items', 'serviceOrder.payments', 'serviceOrder.stage'])->orderBy('starts_at');

        if ($request->filled('from')) {
            $query->where('starts_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->where('starts_at', '<=', $request->date('to'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        return AppointmentResource::collection($query->paginate(50)->withQueryString());
    }

    public function store(StoreAppointmentRequest $request): AppointmentResource
    {
        $appointment = $this->appointmentService->create($request->user(), $request->validated());

        return new AppointmentResource($appointment);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        return new AppointmentResource($appointment->load('client', 'service', 'staff', 'serviceOrder.items', 'serviceOrder.payments', 'serviceOrder.stage'));
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment): AppointmentResource
    {
        $appointment = $this->appointmentService->update($appointment, $request->validated());

        return new AppointmentResource($appointment);
    }

    public function reschedule(RescheduleAppointmentRequest $request, Appointment $appointment): AppointmentResource
    {
        $appointment = $this->appointmentService->reschedule(
            $appointment,
            AppointmentService::parseUtc($request->validated('starts_at')),
            AppointmentService::parseUtc($request->validated('ends_at')),
        );

        return new AppointmentResource($appointment);
    }

    public function updateStatus(UpdateAppointmentStatusRequest $request, Appointment $appointment): AppointmentResource
    {
        $appointment = $this->appointmentService->updateStatus(
            $request->user(), $appointment, $request->validated('status')
        );

        return new AppointmentResource($appointment);
    }
}
