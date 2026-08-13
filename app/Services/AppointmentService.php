<?php

namespace App\Services;

use App\Jobs\SendAppointmentConfirmationJob;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Product;
use App\Models\Reminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agenda de citas. Reservar una cita con servicios seleccionados crea, en el
 * mismo request, una ServiceOrder vinculada via ServiceOrderService::create()
 * - el legacy tenia esta misma logica de "armar la orden desde los servicios
 * elegidos" copiada 1:1 en store() y en chargeAppointment(), y una tercera
 * vez (aunque solo con service_name/total planos, sin itemizar) en
 * ServiceOrdersController::store(). Aqui hay un unico punto de entrada
 * (ServiceOrderService::create), asi que no existe "chargeAppointment" como
 * accion aparte: crear una cita con servicios YA crea su orden.
 */
class AppointmentService
{
    public function __construct(
        private ServiceOrderService $serviceOrderService,
        private ReminderService $reminderService,
    ) {}

    /**
     * @param  array{client_id?: ?int, services: array<int, array{id: int, custom_price?: float|int|string|null}>, user_id?: ?int, client_name: string, client_phone?: ?string, client_email?: ?string, starts_at: string, ends_at: string, notes?: ?string, initial_payment?: float|int|string|null, payment_method?: ?string}  $data
     */
    public function create(User $user, array $data): Appointment
    {
        $appointment = DB::transaction(function () use ($user, $data) {
            $business = $user->business;
            $startsAt = $this->parseUtc($data['starts_at']);
            $endsAt = $this->parseUtc($data['ends_at']);

            $this->assertNoConflict($business, $data['user_id'] ?? null, $startsAt, $endsAt);

            $serviceLines = $this->resolveServiceLines($business, $data['services']);
            $primaryProduct = $serviceLines[0]['product'] ?? null;

            $appointment = Appointment::create([
                'business_id' => $business->id,
                'client_id' => $data['client_id'] ?? null,
                'product_id' => $primaryProduct?->id,
                'user_id' => $data['user_id'] ?? null,
                'client_name' => $data['client_name'],
                'client_phone' => $data['client_phone'] ?? null,
                'client_email' => $data['client_email'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
            ]);

            if ($primaryProduct) {
                $this->serviceOrderService->create($user, [
                    'client_id' => $appointment->client_id,
                    'appointment_id' => $appointment->id,
                    'product_id' => $appointment->product_id,
                    'service_name' => $this->serviceNamesLabel($serviceLines),
                    'items' => $this->serviceLinesToItems($serviceLines),
                    'initial_payment' => $data['initial_payment'] ?? null,
                    'initial_payment_method' => $data['payment_method'] ?? null,
                ]);
            }

            // refresh(), no fresh(): conserva wasRecentlyCreated (el pago
            // inicial pudo haber completado la orden y marcado esta misma
            // cita como 'completed' via un update() aparte - ver la nota en
            // SaleService::createSale sobre por que fresh() rompe el 201).
            return $appointment->refresh()->load('client', 'service', 'staff', 'serviceOrder.items', 'serviceOrder.payments', 'serviceOrder.stage');
        });

        if ($appointment->client_phone) {
            // Fuera de la transaccion (QUEUE_CONNECTION=redis, after_commit
            // en false): un worker rapido no debe tomar el job antes de que
            // la fila exista de verdad.
            SendAppointmentConfirmationJob::dispatch($appointment->id);
            $this->createTwoHourReminder($user, $appointment);
        }

        return $appointment;
    }

    /**
     * Crea la fila Reminder (ver docblock de la clase) que
     * AppointmentsSendTwoHourReminders procesa 2h antes de starts_at.
     */
    private function createTwoHourReminder(User $user, Appointment $appointment): void
    {
        $remindAt = $appointment->starts_at->copy()->subHours(2)->timezone('America/Bogota');

        $this->reminderService->create($appointment->business_id, $user->id, [
            'title' => 'Recordatorio de cita: '.$appointment->client_name,
            'due_date' => $remindAt->toDateString(),
            'notify_time' => $remindAt->format('H:i'),
            'remindable_type' => Appointment::class,
            'remindable_id' => $appointment->id,
        ]);
    }

    /**
     * Reprograma el Reminder de 2h de una cita ya agendada (update()/
     * reschedule() cambian starts_at) - solo si ya existe uno pendiente; si
     * nunca se creo (sin telefono al agendar) no se crea ahora, caso menor
     * que queda fuera de alcance.
     */
    private function rescheduleTwoHourReminder(Appointment $appointment): void
    {
        $remindAt = $appointment->starts_at->copy()->subHours(2)->timezone('America/Bogota');

        Reminder::where('remindable_type', Appointment::class)
            ->where('remindable_id', $appointment->id)
            ->where('status', Reminder::STATUS_PENDING)
            ->update(['due_date' => $remindAt->toDateString(), 'notify_time' => $remindAt->format('H:i')]);
    }

    /** Cancelar la cita hace que ya no tenga sentido avisarle al cliente. */
    private function cancelTwoHourReminder(Appointment $appointment): void
    {
        Reminder::where('remindable_type', Appointment::class)
            ->where('remindable_id', $appointment->id)
            ->where('status', Reminder::STATUS_PENDING)
            ->delete();
    }

    /** Eliminar la cita (soft delete) tampoco debe avisarle al cliente. */
    public function delete(Appointment $appointment): void
    {
        $this->cancelTwoHourReminder($appointment);
        $appointment->delete();
    }

    /**
     * @param  array{client_id?: ?int, services: array<int, array{id: int, custom_price?: float|int|string|null}>, user_id?: ?int, client_name: string, client_phone?: ?string, client_email?: ?string, starts_at: string, ends_at: string, notes?: ?string}  $data
     */
    public function update(Appointment $appointment, array $data): Appointment
    {
        if ($appointment->status === 'completed') {
            throw ValidationException::withMessages([
                'appointment' => 'No se puede editar una cita completada.',
            ]);
        }

        return DB::transaction(function () use ($appointment, $data) {
            $business = $appointment->business;
            $startsAt = $this->parseUtc($data['starts_at']);
            $endsAt = $this->parseUtc($data['ends_at']);

            $this->assertNoConflict($business, $data['user_id'] ?? null, $startsAt, $endsAt, $appointment->id);

            $serviceLines = $this->resolveServiceLines($business, $data['services']);
            $primaryProduct = $serviceLines[0]['product'] ?? null;

            $appointment->update([
                'client_id' => $data['client_id'] ?? null,
                'product_id' => $primaryProduct?->id,
                'user_id' => $data['user_id'] ?? null,
                'client_name' => $data['client_name'],
                'client_phone' => $data['client_phone'] ?? null,
                'client_email' => $data['client_email'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'notes' => $data['notes'] ?? null,
            ]);

            $order = $appointment->serviceOrder;
            if ($primaryProduct && $order && $order->status !== 'cancelled') {
                $this->serviceOrderService->update($order, [
                    'client_id' => $appointment->client_id,
                    'product_id' => $appointment->product_id,
                    'service_name' => $this->serviceNamesLabel($serviceLines),
                    'items' => $this->serviceLinesToItems($serviceLines),
                ]);
            }

            $this->rescheduleTwoHourReminder($appointment);

            return $appointment->fresh()->load('client', 'service', 'staff', 'serviceOrder.items', 'serviceOrder.payments', 'serviceOrder.stage');
        });
    }

    public function reschedule(Appointment $appointment, Carbon $startsAt, Carbon $endsAt): Appointment
    {
        $this->assertNoConflict($appointment->business, $appointment->user_id, $startsAt, $endsAt, $appointment->id);

        $appointment->update(['starts_at' => $startsAt, 'ends_at' => $endsAt, 'status' => 'pending']);
        $this->rescheduleTwoHourReminder($appointment);

        return $appointment->fresh()->load('client', 'service', 'staff', 'serviceOrder.items', 'serviceOrder.payments', 'serviceOrder.stage');
    }

    /**
     * Transiciona el estado de la cita. Cubre lo que en el legacy eran 3
     * acciones separadas (complete/cancel/updateStatus) con la logica de
     * reembolso-y-cancelar-orden duplicada entre las 2 ultimas - aqui hay una
     * sola, y la cancelacion de la orden vinculada reutiliza
     * ServiceOrderService::cancel() en vez de reimplementar el reembolso.
     */
    public function updateStatus(User $user, Appointment $appointment, string $status): Appointment
    {
        return DB::transaction(function () use ($user, $appointment, $status) {
            $appointment->update(['status' => $status]);

            if ($status === 'cancelled') {
                $order = $appointment->serviceOrder;
                if ($order && $order->status !== 'cancelled') {
                    $this->serviceOrderService->cancel($user, $order, 'Reembolso por cancelación de la cita');
                }
                $this->cancelTwoHourReminder($appointment);
            }

            return $appointment->fresh()->load('client', 'service', 'staff', 'serviceOrder.items', 'serviceOrder.payments', 'serviceOrder.stage');
        });
    }

    /**
     * Carbon::parse() conserva el offset original del string (ej.
     * "-05:00") en vez de normalizarlo - el cast 'datetime' de Eloquent
     * guarda la hora tal cual la ve el objeto Carbon en ese momento (sin
     * convertir), asi que un cliente que mande una hora local con offset
     * explicito (no UTC) terminaba corriendo el instante real varias horas
     * sin darse cuenta (ej. "15:00-05:00" quedaba guardado como "15:00
     * UTC", 5 horas adelantado). Normalizar a UTC aca antes de persistir
     * evita ese corrimiento sin importar que offset mande el cliente.
     */
    public static function parseUtc(string $value): Carbon
    {
        return Carbon::parse($value)->utc();
    }

    /**
     * Ningun miembro del equipo puede tener dos citas (no canceladas) que se
     * traslapen en el tiempo. El legacy no validaba esto en absoluto.
     */
    private function assertNoConflict(Business $business, ?int $userId, Carbon $startsAt, Carbon $endsAt, ?int $excludeAppointmentId = null): void
    {
        if (! $userId) {
            return;
        }

        $conflict = Appointment::where('business_id', $business->id)
            ->where('user_id', $userId)
            ->where('status', '!=', 'cancelled')
            ->when($excludeAppointmentId, fn ($q) => $q->where('id', '!=', $excludeAppointmentId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'starts_at' => 'Este miembro del equipo ya tiene una cita en ese horario.',
            ]);
        }
    }

    /**
     * @param  array<int, array{id: int, custom_price?: float|int|string|null}>  $servicesInput
     * @return array<int, array{product: Product, price: float}>
     */
    private function resolveServiceLines(Business $business, array $servicesInput): array
    {
        $productIds = array_column($servicesInput, 'id');
        $products = Product::where('business_id', $business->id)->whereIn('id', $productIds)->get()->keyBy('id');

        $lines = [];
        foreach ($servicesInput as $line) {
            $product = $products->get($line['id']);
            if (! $product) {
                continue;
            }

            $customPrice = isset($line['custom_price']) && $line['custom_price'] !== null && $line['custom_price'] !== ''
                ? (float) $line['custom_price']
                : null;

            $price = ($product->price_varies_at_sale && $customPrice !== null) ? $customPrice : (float) $product->price;

            $lines[] = ['product' => $product, 'price' => $price];
        }

        return $lines;
    }

    /**
     * @param  array<int, array{product: Product, price: float}>  $serviceLines
     */
    private function serviceNamesLabel(array $serviceLines): string
    {
        return collect($serviceLines)->map(fn ($line) => $line['product']->name)->implode(', ');
    }

    /**
     * @param  array<int, array{product: Product, price: float}>  $serviceLines
     * @return array<int, array{name: string, quantity: int, unit_price: float}>
     */
    private function serviceLinesToItems(array $serviceLines): array
    {
        return collect($serviceLines)->map(fn ($line) => [
            'name' => $line['product']->name,
            'quantity' => 1,
            'unit_price' => $line['price'],
        ])->all();
    }
}
