<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessPrintSettings;
use App\Models\Order;
use App\Models\PrintAgent;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Sale;
use App\Models\User;
use App\Support\Printing\EscPosBuilder;
use App\Support\Printing\TicketRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La cola de impresion: quien encola que ticket en que impresora, y como lo
 * reparte entre los agentes.
 *
 * Encolar es inofensivo para quien no imprime: sin la bandera
 * `auto_printing` o sin impresora para ese rol, cada metodo de aqui no hace
 * nada. Asi los disparadores (cobrar, abrir una cuenta, despachar) llaman
 * sin preguntar, y Central Cell, que imprime a mano desde el navegador,
 * sigue exactamente igual.
 *
 * Los tickets se insertan en la misma transaccion que la venta: si la venta
 * falla, no queda una comanda impresa de algo que no existe.
 */
class PrintJobService
{
    public function __construct(private TicketRenderer $renderer) {}

    /**
     * Impresora activa de un rol para la sede de la venta: la de esa sede
     * primero y, si no tiene, la que sirve a todas (branch_id NULL). Tiene
     * que tener agente vinculado: sin agente nadie la consultaria y los
     * tickets se apilarian para imprimirse todos de golpe el dia que se
     * vincule.
     */
    public function printerFor(int $businessId, ?int $branchId, string $role): ?Printer
    {
        return Printer::withoutGlobalScope('business')
            ->where('business_id', $businessId)
            ->where('role', $role)
            ->where('is_active', true)
            ->whereHas('agent', fn (Builder $agent) => $agent->withoutGlobalScope('business')
                ->whereNotNull('token_hash')
                ->whereNull('revoked_at'))
            ->where(fn (Builder $query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->orderByRaw('branch_id IS NULL')
            ->orderBy('id')
            ->first();
    }

    public function businessPrints(?Business $business): bool
    {
        return $business !== null && $business->hasFeature('auto_printing');
    }

    public function businessPrintsKitchen(?Business $business): bool
    {
        return $this->businessPrints($business) && $business->hasFeature('kitchen_printing');
    }

    /**
     * Encola un ticket ya armado. Con $dedupeKey, encolar dos veces lo mismo
     * (un reintento del front, dos disparadores para la misma venta)
     * devuelve el ticket que ya existia en vez de imprimir dos veces.
     */
    public function enqueue(
        Printer $printer,
        string $type,
        EscPosBuilder $ticket,
        ?Sale $sale = null,
        ?string $dedupeKey = null,
        ?User $actor = null,
    ): PrintJob {
        $attributes = [
            'business_id' => $printer->business_id,
            'printer_id' => $printer->id,
            'sale_id' => $sale?->id,
            'type' => $type,
            'payload' => $ticket->toBase64(),
            'dedupe_key' => $dedupeKey,
            'created_by_user_id' => $actor?->id,
        ];

        if ($dedupeKey === null) {
            return PrintJob::create($attributes);
        }

        $existing = PrintJob::withoutGlobalScope('business')->where('dedupe_key', $dedupeKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            // Savepoint: si otra peticion gano la carrera, el error de unique
            // no tumba la transaccion de la venta que nos llamo.
            return DB::transaction(fn () => PrintJob::create($attributes));
        } catch (QueryException $exception) {
            $existing = PrintJob::withoutGlobalScope('business')->where('dedupe_key', $dedupeKey)->first();
            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * Factura al cobrar (o reimpresa a mano). Imprime las copias configuradas
     * en un solo ticket y abre el cajon si entro efectivo. Una reimpresion es
     * una sola copia marcada, sin cajon.
     *
     * No imprime nada si el negocio apago la factura automatica, salvo que
     * sea una reimpresion pedida a mano.
     */
    public function enqueueReceipt(Sale $sale, ?User $actor = null, bool $isReprint = false): ?PrintJob
    {
        $sale->loadMissing('business');
        $business = $sale->business;
        if (! $this->businessPrints($business)) {
            return null;
        }

        $settings = BusinessPrintSettings::forBusiness((int) $business->id);
        if (! $isReprint && ! $settings->auto_print_receipts) {
            return null;
        }

        $printer = $this->printerFor((int) $business->id, $sale->branch_id, Printer::ROLE_COUNTER);
        if ($printer === null) {
            return null;
        }

        if ($isReprint) {
            return $this->enqueue($printer, PrintJob::TYPE_RECEIPT, $this->renderer->receipt($sale, $printer, 'REIMPRESIÓN'), $sale, null, $actor);
        }

        $ticket = $this->receiptCopies($sale, $printer, $settings, $settings->open_cash_drawer && $this->tookCash($sale));

        return $this->enqueue($printer, PrintJob::TYPE_RECEIPT, $ticket, $sale, 'receipt:'.$sale->id, $actor);
    }

    /**
     * Salida de un domicilio: la factura (con sus copias) y, despues de un
     * corte, el ticket del domiciliario. Un solo trabajo para que salgan
     * juntos y en orden. Sin cajon: el efectivo de un domicilio llega
     * despues, con el domiciliario.
     */
    public function enqueueDelivery(Sale $sale, ?User $actor = null): ?PrintJob
    {
        $sale->loadMissing('business');
        $business = $sale->business;
        if (! $this->businessPrints($business)) {
            return null;
        }

        $printer = $this->printerFor((int) $business->id, $sale->branch_id, Printer::ROLE_COUNTER);
        if ($printer === null) {
            return null;
        }

        // Si la factura ya salio al cobrar (una cuenta abierta de domicilio
        // que se cerro antes de despacharla), solo va el ticket.
        $receiptAlreadyPrinted = PrintJob::withoutGlobalScope('business')->where('dedupe_key', 'receipt:'.$sale->id)->exists();
        $deliveryTicket = $this->renderer->delivery($sale, $printer);
        $ticket = $receiptAlreadyPrinted
            ? $deliveryTicket
            : $this->receiptCopies($sale, $printer, BusinessPrintSettings::forBusiness((int) $business->id), false)->append($deliveryTicket);

        return $this->enqueue($printer, PrintJob::TYPE_DELIVERY, $ticket, $sale, 'delivery:'.$sale->id, $actor);
    }

    /** Pagina de prueba desde Configuracion › Impresoras. En la del mostrador, prueba tambien el cajon. */
    public function enqueueTest(Printer $printer, ?User $actor = null): PrintJob
    {
        $ticket = $this->renderer->test($printer, $printer->role === Printer::ROLE_COUNTER);

        return $this->enqueue($printer, PrintJob::TYPE_TEST, $ticket, null, null, $actor);
    }

    /**
     * Aparta los tickets pendientes de las impresoras de un agente. Los
     * apartados cuyo plazo vencio (el agente se cayo a mitad) vuelven a
     * repartirse. Cada reparto cuenta como intento.
     *
     * @return Collection<int, PrintJob>
     */
    public function claim(PrintAgent $agent, int $limit = 5): Collection
    {
        return DB::transaction(function () use ($agent, $limit) {
            $jobs = PrintJob::withoutGlobalScope('business')
                ->where('business_id', $agent->business_id)
                ->whereHas('printer', fn (Builder $printer) => $printer->withoutGlobalScope('business')
                    ->where('print_agent_id', $agent->id)
                    ->where('is_active', true))
                ->where(fn (Builder $query) => $query
                    // Pendiente, salvo que este esperando para reintentar.
                    ->where(fn (Builder $pending) => $pending
                        ->where('status', PrintJob::STATUS_PENDING)
                        ->where(fn (Builder $due) => $due->whereNull('claimed_until')->orWhere('claimed_until', '<=', now())))
                    ->orWhere(fn (Builder $expired) => $expired
                        ->where('status', PrintJob::STATUS_CLAIMED)
                        ->where('claimed_until', '<', now())))
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($jobs as $job) {
                $job->forceFill([
                    'status' => PrintJob::STATUS_CLAIMED,
                    'claimed_until' => now()->addSeconds(PrintJob::CLAIM_SECONDS),
                    'attempts' => $job->attempts + 1,
                ])->save();
            }

            return $jobs->load('printer');
        });
    }

    /**
     * El agente confirma que el ticket salio. Idempotente: confirmar dos
     * veces no repite efectos. Una comanda de pedido online confirmada pasa
     * el pedido a "en preparacion", que es lo que ve el comprador: con la
     * comandera fisica nadie en la cocina toca una pantalla.
     */
    public function acknowledge(PrintAgent $agent, PrintJob $job): PrintJob
    {
        $this->assertBelongsToAgent($agent, $job);
        if ($job->status === PrintJob::STATUS_PRINTED) {
            return $job;
        }

        $job->forceFill([
            'status' => PrintJob::STATUS_PRINTED,
            'printed_at' => now(),
            'claimed_until' => null,
            'last_error' => null,
        ])->save();

        if (in_array($job->type, PrintJob::KITCHEN_TYPES, true)) {
            $this->markOnlineOrderPreparing($job);
        }

        return $job;
    }

    /**
     * El agente no pudo imprimir (impresora apagada, sin papel, IP mala). Se
     * reintenta solo, cada vez esperando mas (RETRY_DELAYS), hasta
     * MAX_ATTEMPTS; despues queda fallido y el POS lo muestra para
     * reintentar a mano. Mientras espera, `claimed_until` dice desde cuando
     * se puede volver a entregar.
     */
    public function fail(PrintAgent $agent, PrintJob $job, string $error): PrintJob
    {
        $this->assertBelongsToAgent($agent, $job);
        if ($job->status === PrintJob::STATUS_PRINTED) {
            return $job;
        }

        $gaveUp = $job->attempts >= PrintJob::MAX_ATTEMPTS;
        $delay = PrintJob::RETRY_DELAYS[min(max($job->attempts - 1, 0), count(PrintJob::RETRY_DELAYS) - 1)];
        $job->forceFill([
            'status' => $gaveUp ? PrintJob::STATUS_FAILED : PrintJob::STATUS_PENDING,
            'claimed_until' => $gaveUp ? null : now()->addSeconds($delay),
            'last_error' => mb_substr($error, 0, 500),
        ])->save();

        return $job;
    }

    /** Reintento manual de un ticket fallido desde el POS. */
    public function retry(PrintJob $job): PrintJob
    {
        if ($job->status !== PrintJob::STATUS_FAILED) {
            throw ValidationException::withMessages(['job' => 'Solo se reintentan los tickets que fallaron.']);
        }

        $job->forceFill(['status' => PrintJob::STATUS_PENDING, 'attempts' => 0, 'last_error' => null])->save();

        return $job;
    }

    private function receiptCopies(Sale $sale, Printer $printer, BusinessPrintSettings $settings, bool $openCashDrawer): EscPosBuilder
    {
        if ($settings->receipt_copies < 2) {
            return $this->renderer->receipt($sale, $printer, null, $openCashDrawer);
        }

        return $this->renderer->receipt($sale, $printer, 'COPIA CLIENTE', $openCashDrawer)
            ->append($this->renderer->receipt($sale, $printer, 'COPIA LOCAL'));
    }

    /** Si en el cobro entro efectivo: el medio de la venta o alguna linea del pago dividido. */
    private function tookCash(Sale $sale): bool
    {
        $cashIds = array_values(array_unique([
            ...Business::paymentMethodIdWithAliases('cash'),
            ...Business::paymentMethodIdWithAliases($sale->business->resolveCashPaymentMethodId()),
        ]));
        if (in_array(strtolower((string) $sale->payment_method), $cashIds, true)) {
            return true;
        }

        return $sale->paymentSplits()
            ->whereIn('payment_method', $cashIds)
            ->where('amount', '>', 0)
            ->exists();
    }

    private function markOnlineOrderPreparing(PrintJob $job): void
    {
        $order = $job->sale_id
            ? Order::withoutGlobalScopes()->where('sale_id', $job->sale_id)->first()
            : null;
        if ($order === null || $order->status !== Order::STATUS_CONFIRMED) {
            return;
        }

        // El dueño firma la transicion, como cuando la pasarela confirma un
        // pago (ver OnlineOrderPaymentService): no hay un empleado detras.
        $owner = Business::withoutGlobalScopes()->find($order->business_id)
            ?->users()->where('is_business_owner', true)->first();
        if ($owner !== null) {
            app(OrderService::class)->transition($owner, $order, Order::STATUS_PREPARING, 'Comanda impresa en la cocina');
        }
    }

    private function assertBelongsToAgent(PrintAgent $agent, PrintJob $job): void
    {
        $job->loadMissing('printer');
        if ($job->printer === null || (int) $job->printer->print_agent_id !== (int) $agent->id) {
            abort(404);
        }
    }
}
