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
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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
        ];
    }

    public function store(StoreCashClosingRequest $request): CashClosingResource
    {
        $closing = $this->cashClosingService->closeCash($request->user(), $request->validated());

        return new CashClosingResource($closing->load('closedByUser'));
    }

    public function show(CashClosing $cashClosing): CashClosingResource
    {
        return new CashClosingResource($cashClosing->load('closedByUser'));
    }

    public function update(UpdateCashClosingRequest $request, CashClosing $cashClosing): CashClosingResource
    {
        $updated = $this->cashClosingService->updateCashClosing($cashClosing, $request->validated());

        return new CashClosingResource($updated);
    }

    public function undo(CashClosing $cashClosing): Response
    {
        $this->cashClosingService->undoCashClosing($cashClosing);

        return response()->noContent();
    }
}
