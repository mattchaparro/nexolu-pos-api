<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCashClosingRequest;
use App\Http\Requests\Api\V1\UpdateCashClosingRequest;
use App\Http\Resources\Api\V1\CashClosingResource;
use App\Http\Resources\Api\V1\CashShiftResource;
use App\Models\CashClosing;
use App\Models\CashShift;
use App\Services\CashClosingService;
use App\Services\GatewayReconciliationService;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class CashClosingController extends Controller
{
    public function __construct(private CashClosingService $cashClosingService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $closings = CashClosing::with('closedByUser')
            ->latest('date')
            ->paginate(20)
            ->withQueryString();

        return CashClosingResource::collection($closings);
    }

    /** Dias anteriores a hoy con actividad pero sin cierre de caja, para la cola de "ponerse al dia". */
    public function pendingDates(Request $request): array
    {
        return ['dates' => $this->cashClosingService->pendingDates((int) $request->user()->business_id)];
    }

    /**
     * Vista previa de un cierre antes de confirmarlo: los totales calculados
     * para $date, mas los turnos que ESTE cierre auto-cerraria si se confirma
     * ahora (mismo criterio que CashClosingService::autoCloseOpenShifts).
     */
    public function preview(Request $request): array
    {
        $user = $request->user();
        $date = $request->string('date', now()->toDateString())->toString();

        $previousClosing = CashClosing::where('business_id', $user->business_id)
            ->where('date', '<', $date)
            ->latest('date')
            ->first();
        $suggestedOpeningCash = (float) ($previousClosing?->base_for_next_day ?? 0);
        $openingCash = (float) $request->input('opening_cash', $suggestedOpeningCash);

        $totals = $this->cashClosingService->calculateTotals($date, (int) $user->business_id, $openingCash);
        $existingClosing = CashClosing::where('business_id', $user->business_id)->where('date', $date)->first();

        $shiftsToAutoClose = CashShift::where('business_id', $user->business_id)
            ->autoClosableOn($date)
            ->with('user')
            ->orderBy('opened_at')
            ->get();

        return [
            'date' => $date,
            'totals' => $totals,
            'suggested_opening_cash' => $suggestedOpeningCash,
            'existing_closing' => $existingClosing ? new CashClosingResource($existingClosing) : null,
            'shifts_to_auto_close' => CashShiftResource::collection($shiftsToAutoClose),
            // Cuadre contra la pasarela: lo que el POS registro por medios
            // electronicos frente a lo que el proveedor dice haber cobrado.
            // Nulo si el negocio no tiene pasarela conectada -- mostrarle un
            // cuadre en ceros a quien solo cobra en efectivo seria ruido.
            'gateway_reconciliation' => $this->gatewayReconciliation($request, $date),
        ];
    }

    /**
     * El cuadre del dia contra la pasarela.
     *
     * Va en el preview del cierre y no en una pantalla aparte porque es
     * justo cuando el comerciante esta contando: si el QR del datafono
     * cobro algo que nadie registro, este es el momento en que todavia se
     * acuerda de esa venta. Dias despues, cuando aparezca en el extracto,
     * ya no.
     *
     * @return array<string, mixed>|null
     */
    private function gatewayReconciliation(Request $request, string $date): ?array
    {
        $business = $request->user()?->business;
        if ($business === null || $business->activePaymentGateway() === null) {
            return null;
        }

        $resumen = app(GatewayReconciliationService::class)->summary(
            $business,
            Carbon::parse($date)->startOfDay(),
            Carbon::parse($date)->endOfDay(),
        );

        return [
            'pos' => $resumen['pos'],
            'gateway' => $resumen['gateway'],
            'balanced' => $resumen['balanced'],
            'unmatched_payments' => $resumen['unmatched_payments']->map(fn ($p) => [
                'amount' => (float) $p->amount,
                'payment_method' => $p->payment_method,
                // Es el numero del voucher fisico: con eso reclama.
                'approval_number' => $p->approval_number,
                'occurred_at' => $p->occurred_at?->toIso8601String(),
            ])->all(),
            'unmatched_sales' => $resumen['unmatched_sales']->map(fn ($s) => [
                'id' => $s->id,
                'invoice_number' => $s->invoice_number,
                'total' => (float) $s->total,
                'payment_method' => $s->payment_method,
                'created_at' => $s->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    public function store(StoreCashClosingRequest $request): CashClosingResource
    {
        $closing = $this->cashClosingService->closeCash($request->user(), $request->validated());

        AuditLogger::log($request->user()->hasRole('admin') ? 'cash_closing.created' : 'cash_closing.created.employee', [
            'cash_closing_id' => $closing->id,
            'date' => $closing->date?->format('Y-m-d'),
            'expected_cash' => $closing->expected_cash,
        ]);

        return new CashClosingResource($closing->load('closedByUser'));
    }

    public function show(CashClosing $cashClosing): CashClosingResource
    {
        return new CashClosingResource($cashClosing->load('closedByUser'));
    }

    public function update(UpdateCashClosingRequest $request, CashClosing $cashClosing): CashClosingResource
    {
        $updated = $this->cashClosingService->updateCashClosing($cashClosing, $request->validated());

        AuditLogger::log('cash_closing.updated', [
            'cash_closing_id' => $updated->id,
            'date' => $updated->date?->format('Y-m-d'),
            'expected_cash' => $updated->expected_cash,
        ]);

        return new CashClosingResource($updated);
    }

    public function undo(CashClosing $cashClosing): Response
    {
        AuditLogger::log('cash_closing.undone', [
            'cash_closing_id' => $cashClosing->id,
            'date' => $cashClosing->date?->format('Y-m-d'),
        ]);

        $this->cashClosingService->undoCashClosing($cashClosing);

        return response()->noContent();
    }
}
