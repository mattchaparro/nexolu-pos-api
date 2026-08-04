<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Product;
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
    public function __construct(private ServiceOrderService $serviceOrderService) {}

    /**
     * @param  array{client_id?: ?int, services: array<int, array{id: int, custom_price?: float|int|string|null}>, user_id?: ?int, client_name: string, client_phone?: ?string, client_email?: ?string, starts_at: string, ends_at: string, notes?: ?string, initial_payment?: float|int|string|null, payment_method?: ?string}  $data
     */
    public function create(User $user, array $data): Appointment
    {
        return DB::transaction(function () use ($user, $data) {
            $business = $user->business;
            $startsAt = Carbon::parse($data['starts_at']);
            $endsAt = Carbon::parse($data['ends_at']);

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
            return $appointment->refresh()->load('client', 'service', 'staff', 'serviceOrder.items', 'serviceOrder.payments');
        });
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
            $startsAt = Carbon::parse($data['starts_at']);
            $endsAt = Carbon::parse($data['ends_at']);

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

            return $appointment->fresh()->load('client', 'service', 'staff', 'serviceOrder.items', 'serviceOrder.payments');
        });
    }

    public function reschedule(Appointment $appointment, Carbon $startsAt, Carbon $endsAt): Appointment
    {
        $this->assertNoConflict($appointment->business, $appointment->user_id, $startsAt, $endsAt, $appointment->id);

        $appointment->update(['starts_at' => $startsAt, 'ends_at' => $endsAt, 'status' => 'pending']);

        return $appointment->fresh()->load('client', 'service', 'staff', 'serviceOrder.items', 'serviceOrder.payments');
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
            }

            return $appointment->fresh()->load('client', 'service', 'staff', 'serviceOrder.items', 'serviceOrder.payments');
        });
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
