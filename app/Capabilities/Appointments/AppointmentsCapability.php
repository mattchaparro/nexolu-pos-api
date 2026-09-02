<?php

namespace App\Capabilities\Appointments;

use App\Capabilities\Capability;
use App\Capabilities\Support\CapsRows;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/** Tool: citas_agendadas. Que hay agendado y para quien. */
class AppointmentsCapability implements Capability
{
    use CapsRows;

    /** Cuanto mira hacia adelante cuando no se pide un rango. */
    private const DEFAULT_LOOKAHEAD_DAYS = 30;

    public function requiredPermission(): ?string
    {
        return 'appointments.manage';
    }

    public function requiredFeature(): ?string
    {
        return 'scheduling';
    }

    public function rules(): array
    {
        return [
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'estado' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }

    public function execute(Business $business, User $user, array $arguments): array
    {
        // Por defecto mira hacia ADELANTE, no hacia atras como el resto de las
        // capacidades: preguntar por citas es preguntar por lo que viene. El
        // rango estandar (ultimos 30 dias) devolveria justo lo contrario.
        $start = ! empty($arguments['desde'])
            ? Carbon::createFromFormat('Y-m-d', $arguments['desde'])->startOfDay()
            : now()->startOfDay();
        $end = ! empty($arguments['hasta'])
            ? Carbon::createFromFormat('Y-m-d', $arguments['hasta'])->endOfDay()
            : now()->addDays(self::DEFAULT_LOOKAHEAD_DAYS)->endOfDay();

        if ($start->gt($end)) {
            throw ValidationException::withMessages(['desde' => 'La fecha inicial es posterior a la final.']);
        }

        $query = Appointment::query()
            ->whereBetween('starts_at', [$start, $end])
            ->with(['client:id,name', 'service:id,name', 'staff:id,name']);

        if (! empty($arguments['estado'])) {
            $query->where('status', (string) $arguments['estado']);
        }

        $appointments = $query->orderBy('starts_at')->limit(self::MAX_ROWS)->get();

        $rows = $appointments->map(fn (Appointment $appointment) => [
            'fecha' => $appointment->starts_at->toDateString(),
            'hora' => $appointment->starts_at->format('H:i'),
            'cliente' => $appointment->client?->name ?: ($appointment->client_name ?: 'Sin nombre'),
            'servicio' => $appointment->service?->name ?? 'Sin servicio asociado',
            'atiende' => $appointment->staff?->name,
            'estado' => $appointment->status,
        ])->values()->all();

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[$row['estado']] = ($byStatus[$row['estado']] ?? 0) + 1;
        }

        return [
            'desde' => $start->toDateString(),
            'hasta' => $end->toDateString(),
            'total_citas' => count($rows),
            'por_estado' => $byStatus,
            'citas' => $this->capRows($rows),
        ];
    }
}
