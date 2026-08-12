<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ServiceOrder;
use App\Models\ServicePayment;
use App\Models\User;
use App\Support\PaymentRefunder;
use Illuminate\Validation\ValidationException;

/**
 * Ordenes de servicio: cobro por un servicio prestado (con o sin cita
 * asociada), que puede abonarse de a poco. Unico lugar donde se crea una
 * ServiceOrder - tanto la creacion directa (POST /service-orders) como la
 * que dispara AppointmentService al agendar con servicios seleccionados
 * pasan por create(), en vez de reimplementar la misma logica cada una (el
 * legacy la tenia copiada 3 veces: ServiceOrdersController::store,
 * AppointmentsController::store y AppointmentsController::chargeAppointment).
 *
 * Etapas (workflow/kanban): un negocio puede tener asignado como mucho un
 * ServiceWorkflow (ver Business::serviceWorkflow(), administrado por
 * superadmin). Si lo tiene, cada orden nueva arranca en su etapa inicial
 * (create() de aca abajo) y ServiceOrder::recalculateStatus() la mueve sola
 * a la etapa de "pago completo" si el workflow tiene una configurada (ver
 * ServiceOrder::applyPaymentCompleteStage()). Mover una orden de etapa a
 * mano (incluyendo la accion mark_order_paid) es setStage() aca abajo.
 */
class ServiceOrderService
{
    /**
     * @param  array{client_id?: ?int, appointment_id?: ?int, product_id?: ?int, service_name: string, total?: float|int|string|null, items?: array<int, array{name: string, quantity: float|int|string, unit_price: float|int|string, user_id?: ?int, notes?: ?string}>, initial_payment?: float|int|string|null, initial_payment_method?: ?string, notes?: ?string}  $data
     */
    public function create(User $user, array $data): ServiceOrder
    {
        $business = $user->business;
        $items = $data['items'] ?? [];

        $total = ! empty($items)
            ? round(collect($items)->sum(fn ($i) => (float) $i['quantity'] * (float) $i['unit_price']), 2)
            : (float) ($data['total'] ?? 0);

        $initialPayment = min((float) ($data['initial_payment'] ?? 0), $total);

        $business->loadMissing('serviceWorkflow.stages');
        $initialStage = $business->serviceWorkflow?->initialStage();

        $order = ServiceOrder::create([
            'business_id' => $business->id,
            'client_id' => $data['client_id'] ?? null,
            'appointment_id' => $data['appointment_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'user_id' => $user->id,
            'stage_id' => $initialStage?->id,
            'service_name' => $data['service_name'],
            'total' => $total,
            'amount_paid' => $initialPayment,
            'notes' => $data['notes'] ?? null,
            'status' => 'pending',
        ]);

        $this->syncItems($order, $items);

        if ($initialPayment > 0.009) {
            $this->createPayment($user, $order, $initialPayment, $data['initial_payment_method'] ?? null, 'Pago inicial');
        }

        $order->recalculateStatus();
        $this->maybeCompleteLinkedAppointment($order);

        // load(), no fresh(): fresh() trae una instancia nueva de la BD y
        // pierde wasRecentlyCreated, con lo que el controller devolveria 200
        // en vez de 201 al crear (mismo cuidado que en SaleService::createSale).
        return $order->load('items', 'payments', 'client', 'stage');
    }

    /**
     * @param  array{client_id?: ?int, product_id?: ?int, service_name: string, total?: float|int|string|null, items?: array<int, array{name: string, quantity: float|int|string, unit_price: float|int|string, user_id?: ?int, notes?: ?string}>, notes?: ?string}  $data
     */
    public function update(ServiceOrder $order, array $data): ServiceOrder
    {
        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages([
                'order' => 'No se puede editar una orden cancelada.',
            ]);
        }

        $items = $data['items'] ?? [];
        $computedTotal = ! empty($items)
            ? round(collect($items)->sum(fn ($i) => (float) $i['quantity'] * (float) $i['unit_price']), 2)
            : (float) ($data['total'] ?? (float) $order->total);

        // El total nunca baja de lo ya pagado: si a una orden ya abonada se le
        // quitan/abaratan items, ese abono no puede quedar "regalado" en los
        // libros (mismo bug que el legacy solo corregia en el flujo desde una
        // cita - auditoria #13 - y no en la edicion directa de la orden).
        $total = max($computedTotal, (float) $order->amount_paid);

        $order->update([
            'client_id' => $data['client_id'] ?? $order->client_id,
            'product_id' => $data['product_id'] ?? $order->product_id,
            'service_name' => $data['service_name'],
            'total' => $total,
            'notes' => $data['notes'] ?? null,
        ]);

        $order->items()->delete();
        $this->syncItems($order, $items);

        $order->recalculateStatus();

        return $order->load('items', 'payments', 'client', 'stage');
    }

    public function pay(User $user, ServiceOrder $order, float $amount, ?string $paymentMethod, ?string $notes = null): ServiceOrder
    {
        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages(['order' => 'La orden esta cancelada.']);
        }
        if ($order->status === 'paid') {
            throw ValidationException::withMessages(['order' => 'La orden ya esta pagada.']);
        }

        $maxAbono = (float) $order->total - (float) $order->amount_paid;
        $amount = min($amount, $maxAbono);
        if ($amount <= 0.009) {
            throw ValidationException::withMessages(['amount' => 'La orden ya esta saldada.']);
        }

        $this->createPayment($user, $order, $amount, $paymentMethod, $notes);

        $order->amount_paid = (float) $order->amount_paid + $amount;
        $order->recalculateStatus();

        $this->maybeCompleteLinkedAppointment($order);

        return $order->load('items', 'payments', 'client', 'stage');
    }

    /**
     * Mueve la orden a una etapa del workflow asignado al negocio. Si la
     * etapa tiene la accion mark_order_paid y la orden todavia no esta
     * pagada, registra un ServicePayment por el saldo pendiente (nunca se
     * marca "pagado" sin una fila que lo respalde - el cierre de caja y el
     * resumen del dia leen el ingreso desde ahi, no desde amount_paid).
     */
    public function setStage(User $user, ServiceOrder $order, int $stageId): ServiceOrder
    {
        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages(['order' => 'La orden esta cancelada.']);
        }

        $business = $user->business;
        $business->loadMissing('serviceWorkflow.stages');
        $workflow = $business->serviceWorkflow;

        if (! $workflow) {
            throw ValidationException::withMessages(['stage_id' => 'Este negocio no tiene un workflow configurado.']);
        }

        $stage = $workflow->stages->firstWhere('id', $stageId);
        if (! $stage) {
            throw ValidationException::withMessages(['stage_id' => 'Etapa no válida para este negocio.']);
        }

        $order->stage_id = $stage->id;

        if ($stage->hasAction('mark_order_paid') && $order->status !== 'paid') {
            $pending = round((float) $order->total - (float) $order->amount_paid, 2);
            if ($pending > 0.009) {
                $this->createPayment($user, $order, $pending, null, 'Registrado automáticamente al mover a esta etapa');
                $order->amount_paid = (float) $order->amount_paid + $pending;
            }
            $order->recalculateStatus();
        } else {
            $order->save();
        }

        return $order->load('items', 'payments', 'client', 'stage');
    }

    /**
     * Reembolsa (via fila negativa, ver PaymentRefunder) y cancela. No borra
     * la orden - las ordenes de servicio no tienen accion de eliminar en
     * esta API, solo cancelar (igual que Apartados y Fiados).
     *
     * Tambien cancela la cita vinculada si tiene una - y AppointmentService
     * llama a este mismo metodo en la direccion opuesta (cancelar una cita
     * cancela su orden). No hay ciclo real: el update() de la cita ya
     * cancelada aqui abajo es un no-op (whereNotIn excluye 'cancelled'), asi
     * que es seguro invocar este metodo desde cualquiera de los dos lados.
     */
    public function cancel(User $user, ServiceOrder $order, string $reason = 'Reembolso por cancelación de la orden'): void
    {
        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages(['order' => 'La orden ya esta cancelada.']);
        }

        $this->refundPayments($user, $order, $reason);
        $order->update(['status' => 'cancelled', 'amount_paid' => 0]);

        if ($order->appointment_id) {
            Appointment::where('id', $order->appointment_id)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->update(['status' => 'cancelled']);
        }
    }

    private function refundPayments(User $user, ServiceOrder $order, string $reason): void
    {
        PaymentRefunder::refundGroupedByMethod($order->payments(), fn (string $method, float $total) => [
            'service_order_id' => $order->id,
            'business_id' => $order->business_id,
            'amount' => -$total,
            'payment_method' => $method,
            'notes' => $reason,
            'recorded_by_user_id' => $user->id,
        ]);
    }

    private function createPayment(User $user, ServiceOrder $order, float $amount, ?string $paymentMethod, ?string $notes): ServicePayment
    {
        $business = $user->business;
        $method = strtolower(trim((string) ($paymentMethod ?? $business->resolveCashPaymentMethodId())));
        // Igual que en Apartados: no tiene sentido "pagar" una orden con
        // fiado, eso solo mueve la deuda de un lado a otro.
        $business->assertValidPaymentMethod($method, forbidCredit: true);

        return ServicePayment::create([
            'service_order_id' => $order->id,
            'business_id' => $order->business_id,
            'amount' => $amount,
            'payment_method' => $method,
            'notes' => $notes,
            'recorded_by_user_id' => $user->id,
        ]);
    }

    /**
     * @param  array<int, array{name: string, quantity: float|int|string, unit_price: float|int|string, user_id?: ?int, notes?: ?string}>  $items
     */
    private function syncItems(ServiceOrder $order, array $items): void
    {
        foreach ($items as $i => $item) {
            $order->items()->create([
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'notes' => $item['notes'] ?? null,
                'user_id' => $item['user_id'] ?? null,
                'sort_order' => $i,
            ]);
        }
    }

    private function maybeCompleteLinkedAppointment(ServiceOrder $order): void
    {
        if ($order->status === 'paid' && $order->appointment_id) {
            Appointment::where('id', $order->appointment_id)->update(['status' => 'completed']);
        }
    }
}
